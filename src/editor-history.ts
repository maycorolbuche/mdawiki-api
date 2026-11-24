import { Hono } from 'hono'
import { pageVersion } from './article-writes'
import { activeAccount, readSession } from './accounts'
import { identifier, legacyIdentifier, mediaUrl } from './pages'
import { query, validSlug, type Env } from './types'

type Page = { id: number; kind: string; title: string | null; source: string | null; updated_at: string | null; updated_by: number | null }
type Revision = { id: number; avatar_filename: string | null }
const error = (code: string, message: string) => ({ error: { code, message } })
const loginRequired = error('login_required', 'Entre na sua conta para continuar.')
export const editorHistory = new Hono<{ Bindings: Env }>()

/** Mantém o contrato do histórico e respeita o nível de acesso de cada revisão. */
editorHistory.get('/pages/:slug/history', async c => {
  c.header('Cache-Control', 'no-store')
  const user = await activeAccount(c, await readSession(c))
  if (!user) return c.json(loginRequired, 401)
  const slug = c.req.param('slug')
  const before = c.req.query('before')
  if (!validSlug(slug)) return c.json(error('invalid_slug', 'Endereço inválido.'), 422)
  if (before !== undefined && (!/^[1-9]\d{0,18}$/.test(before) || BigInt(before) > 9223372036854775807n)) {
    return c.json(error('invalid_pagination', 'Paginação inválida.'), 422)
  }
  const key = identifier(slug)
  const db = c.env.DB
  const page = await query(db, `SELECT id, kind, title, source, updated_at, updated_by FROM pages
    WHERE legacy_identifier NOT LIKE 'page@%' AND (identifier = ? OR legacy_identifier = ?)
    ORDER BY identifier = ? DESC LIMIT 1`, key, legacyIdentifier(slug), key).first<Page>()
  if (!page) return c.json(error('not_found', 'Página não encontrada.'), 404)
  if (page.kind === 'image') return c.json(error('image_editor_unavailable', 'Consulte o histórico na rota de imagens.'), 501)
  const rows = await query(db, `SELECT r.revision_number AS id, r.title, COALESCE(r.source, '') AS source, r.created_at,
    COALESCE(NULLIF(u.display_name, ''), u.name) AS author, u.avatar_filename,
    CASE WHEN u.is_active = 1 THEN u.public_id END AS author_profile_id
    FROM page_revisions r LEFT JOIN users u ON u.id = r.created_by
    WHERE r.page_id = ? AND r.revision_number < CAST(? AS INTEGER) AND COALESCE(r.access_level_id, 1) <= ?
      AND COALESCE(r.is_expired, 0) = 0 ORDER BY r.revision_number DESC LIMIT 51`,
    page.id, before ?? '9223372036854775807', Math.max(1, user.NIVEL)).all<Revision>()
  const api = new URL(c.req.url).origin
  const revisions = rows.results.slice(0, 50).map(({ avatar_filename, ...revision }) => ({
    ...revision, author_photo: avatar_filename ? mediaUrl(api, 'users/' + avatar_filename) : null,
  }))
  const base_version = await pageVersion(page)
  return c.json({ data: { title: page.title, revisions, next_cursor: rows.results.length > 50 ? revisions[49].id : null, base_version } })
})

/** A API PHP só habilita o catálogo de reprocessamento no modo normalizado. */
editorHistory.get('/catalog', async c => {
  c.header('Cache-Control', 'no-store')
  const type = c.req.query('type') ?? 'paginas'
  if (type !== 'atualizar') return c.json(error('catalog_not_found', 'Lista não encontrada.'), 404)
  const user = await activeAccount(c, await readSession(c))
  if (!user) return c.json(loginRequired, 401)
  if (user.role !== 'admin') return c.json(error('permission_denied', 'Acesso restrito a administradores.'), 403)
  const rawLimit = c.req.query('limit') ?? '50'
  const rawOffset = c.req.query('offset') ?? '0'
  const limit = Number(rawLimit)
  const offset = Number(rawOffset)
  if (!/^\d+$/.test(rawLimit) || !/^\d+$/.test(rawOffset) || limit < 1 || limit > 100 || offset > 1000000) {
    return c.json(error('invalid_pagination', 'Paginação inválida.'), 422)
  }
  const rows = await query(c.env.DB, `SELECT p.id, p.slug, COALESCE(p.title, p.slug) AS title
    FROM page_rebuild_queue q JOIN pages p ON p.id = q.page_id
    WHERE p.kind <> 'image' AND p.legacy_identifier NOT LIKE 'page@%'
    ORDER BY title, p.id LIMIT ? OFFSET ?`, limit + 1, offset).all()
  return c.json({ data: { type, items: rows.results.slice(0, limit), next_offset: rows.results.length > limit ? offset + limit : null } })
})
