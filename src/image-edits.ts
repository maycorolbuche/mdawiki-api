import { mailRecipients, notifyEvent } from './wiki-mail'
import { Hono } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { imageSize } from 'image-size'
import { activeAccount, readSession, same, allowedAttempt } from './accounts'
import { publicImage } from './directory'
import { identifier, legacyIdentifier, mediaUrl } from './pages'
import { query, validSlug, type Env } from './types'

const maxBytes = 5_000_000
const formats: Record<string, string> = { jpg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' }
const error = (code: string, message: string) => ({ error: { code, message } })
const conflict = error('edit_conflict', 'A imagem mudou. Atualize a página e tente novamente.')
const invalidUpload = error('invalid_upload', 'Envie uma imagem PNG, JPEG, GIF ou WebP de até 5 MB e 20 megapixels.')

function readImage(bytes: Uint8Array) {
  if (bytes.length > maxBytes) return null
  try {
    const { type, width, height } = imageSize(bytes)
    const extension = type ?? ''
    if (!Object.hasOwn(formats, extension) || !width || !height || width * height > 20_000_000) return null
    return { extension, width, height, size_bytes: bytes.length }
  } catch {
    return null
  }
}

function decodeUpload(data: unknown) {
  if (typeof data !== 'string' || data.length > Math.ceil(maxBytes / 3) * 4 + 32) return null
  const encoded = /^data:image\/(?:png|jpeg|gif|webp);base64,([a-z0-9+/]+={0,2})$/i.exec(data)?.[1]
  if (!encoded) return null
  try {
    return Uint8Array.from(atob(encoded), character => character.charCodeAt(0))
  } catch {
    return null
  }
}

type Image = { id: number; slug: string; file_id: number | null; access_level_id: number }
export const imageEdits = new Hono<{ Bindings: Env }>()

/** Muda a referência no D1 depois de confirmar que o objeto existe no R2. */
imageEdits.put('/images/:slug', bodyLimit({ maxSize: 6_700_000 }), async c => {
  c.header('Cache-Control', 'no-store')
  const session = await readSession(c)
  if (!c.req.header('Origin') || !session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) {
    return c.json(error('csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.'), 403)
  }
  const user = await activeAccount(c, session)
  if (!user) return c.json(error('login_required', 'Entre na sua conta para continuar.'), 401)
  if (user.role !== 'user' && user.role !== 'admin') {
    return c.json(error('permission_denied', 'Você não pode alterar esta imagem.'), 403)
  }
  if (!await allowedAttempt(c, 'media-user:' + user.ID, 20)) {
    return c.json(error('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const name = c.req.param('slug')
  const canonical = name.normalize('NFC').replace(/[^\p{L}\p{M}\p{N}]+/gu, '-').replace(/^-|-$/g, '')
  const key = identifier(name)
  if (!validSlug(name) || name.includes('@') || !key) {
    return c.json(error('invalid_image_name', 'Informe um nome de imagem válido.'), 422)
  }
  const body = await c.req.json<{ data?: unknown; clear?: boolean; revision_id?: unknown; base_version?: unknown }>().catch(() => null)
  const db = c.env.DB
  const image = await query(db, `SELECT id, slug, file_id, access_level_id, meta FROM images
    WHERE identifier = ? OR legacy_identifier = ? ORDER BY identifier = ? DESC LIMIT 1`,
    key, 'imagem@' + legacyIdentifier(name), key).first<Image & { meta: string }>()
  if (image && image.access_level_id > 1) {
    return c.json(error('permission_denied', 'Você não pode alterar esta imagem.'), 403)
  }
  if (!body || body.base_version !== String(image?.file_id ?? '')) return c.json(conflict, 409)
  let fileId: number | null = null
  let meta = '{}'

  if (body.clear === true || Object.hasOwn(body, 'revision_id')) {
    if (!image) return c.json(error('image_not_found', 'Imagem não encontrada.'), 404)
    if (body.clear !== true) {
      const value = body.revision_id
      const id = typeof value === 'number' || typeof value === 'string' && /^[0-9]+$/.test(value) ? Number(value) : NaN
      if (!Number.isSafeInteger(id) || id < 1) return c.json(error('invalid_revision', 'Informe uma revisão válida.'), 422)
      const revision = await query(db, `SELECT f.id, f.storage_key FROM image_revisions r
        JOIN files f ON f.id = r.file_id WHERE r.id = ? AND r.image_id = ? AND r.access_level_id <= 1`,
        id, image.id).first<{ id: number; storage_key: string }>()
      const object = revision ? await c.env.MEDIA.head(revision.storage_key) : null
      if (!revision || !object) return c.json(error('revision_not_found', 'O arquivo desta revisão não está disponível.'), 404)
      const stored = object.size <= maxBytes ? await c.env.MEDIA.get(revision.storage_key) : null
      const properties = stored ? readImage(new Uint8Array(await stored.arrayBuffer())) : null
      meta = JSON.stringify(properties ?? { extension: revision.storage_key.split('.').pop(), width: null, height: null, size_bytes: object.size })
      fileId = revision.id
    }
  } else {
    const bytes = decodeUpload(body.data)
    const info = bytes && readImage(bytes)
    if (!info || !bytes) return c.json(invalidUpload, 422)
    const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)),
      byte => byte.toString(16).padStart(2, '0')).join('')
    const storageKey = 'articles/' + hash + '.' + info.extension
    const mime = formats[info.extension]
    // Objetos por hash são imutáveis. Uma colisão de edição pode deixar um objeto órfão,
    // mas sua chave só é servida pela API se estiver vinculada no catálogo.
    await c.env.MEDIA.put(storageKey, bytes, { sha256: hash, httpMetadata: { contentType: mime } })
    await query(db, `INSERT INTO files (sha256, storage_key, mime_type, size_bytes) VALUES (?, ?, ?, ?)
      ON CONFLICT(storage_key) DO NOTHING`, hash, storageKey, mime, bytes.length).run()
    const file = await query(db, 'SELECT id FROM files WHERE storage_key = ?', storageKey).first<{ id: number }>()
    if (!file) throw new Error('Registro do arquivo não encontrado.')
    fileId = file.id
    meta = JSON.stringify(info)
  }

  if (image) {
    const changed = await query(db, `UPDATE images SET file_id = ?, meta = ?, updated_by = CASE WHEN file_id IS NOT ? THEN ? ELSE updated_by END,
      updated_at = CASE WHEN file_id IS NOT ? THEN CURRENT_TIMESTAMP ELSE updated_at END
      WHERE id = ? AND file_id IS ? AND access_level_id <= 1 RETURNING id`,
      fileId, meta, fileId, user.ID, fileId, image.id, image.file_id).first()
    if (!changed) return c.json(conflict, 409)
  } else {
    const inserted = await query(db, `INSERT INTO images (identifier, slug, title, meta, file_id, access_level_id,
      created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
      ON CONFLICT(identifier) DO NOTHING RETURNING id`,
      key, canonical, canonical.replaceAll('-', ' '), meta, fileId, user.ID, user.ID).first()
    if (!inserted) return c.json(conflict, 409)
  }
  const api = new URL(c.req.url).origin
  const data = await publicImage(db, api, image?.slug ?? canonical)
  if (data && (!image || image.file_id !== fileId)) {
    const action = fileId === null ? 'delete' : image?.file_id == null ? 'new' : 'update'
    const previous = action === 'delete' && mailRecipients(c.env.MAIL_ADMIN_TO).length > 0
      ? await query(db, 'SELECT storage_key FROM files WHERE id = ?', image?.file_id ?? null)
        .first<{ storage_key: string }>().catch(() => null)
      : null
    const url = data.url ?? mediaUrl(api, previous?.storage_key ?? null)
    const properties = action === 'delete' && image ? JSON.parse(image.meta) : data.meta
    notifyEvent(c, 'img', action, data.title || data.slug, user.NOME || user.EMAIL, {
      Endereço: data.slug, Arquivo: url, Formato: properties?.extension,
      Largura: properties?.width, Altura: properties?.height, 'Tamanho em bytes': properties?.size_bytes,
    }, '/i/' + encodeURIComponent(data.slug), url)
  }
  return c.json({ data })
})
