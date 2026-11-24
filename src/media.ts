import { identifier, legacyIdentifier } from './pages'
import { query, type Env } from './types'

const mimeTypes: Record<string, string> = {
  avif: 'image/avif', gif: 'image/gif', jpeg: 'image/jpeg', jpg: 'image/jpeg',
  png: 'image/png', svg: 'image/svg+xml', webp: 'image/webp',
}

/** Só entrega objetos autorizados no catálogo, inclusive as versões históricas públicas. */
export async function serveMedia(env: Env, group: string, file: string, head: boolean) {
  if (!/^[a-z0-9_.-]{1,180}$/i.test(file) || !['articles', 'users'].includes(group)) return null
  const mime = mimeTypes[file.split('.').pop()?.toLowerCase() ?? '']
  if (!mime) return null
  const key = group + '/' + file
  const allowed = group === 'users'
    ? await query(env.DB, 'SELECT id FROM users WHERE avatar_filename = ? AND is_active = 1 LIMIT 1', file).first()
    : await query(env.DB, `SELECT f.id FROM files f WHERE f.storage_key = ? AND EXISTS (
        SELECT 1 FROM images i WHERE i.access_level_id <= 1 AND (i.file_id = f.id OR EXISTS (
          SELECT 1 FROM image_revisions r WHERE r.image_id = i.id AND r.file_id = f.id AND r.access_level_id <= 1
        ))) LIMIT 1`, key).first()
  if (!allowed) return null
  const body = head ? null : await env.MEDIA.get(key)
  const object = head ? await env.MEDIA.head(key) : body
  if (!object) return null
  return new Response(body?.body ?? null, {
    headers: {
      'Content-Type': mime,
      'Content-Length': String(object.size),
      'Cache-Control': 'no-cache',
      'ETag': object.httpEtag,
      'X-Content-Type-Options': 'nosniff',
      'Content-Security-Policy': "sandbox; default-src 'none'; style-src 'unsafe-inline'",
    },
  })
}

/** O endereço do artigo é estável. A imagem pode mudar ou passar a existir posteriormente. */
export async function serveArticleImage(env: Env, slug: string, head: boolean) {
  const key = identifier(slug)
  const file = await query(env.DB, `SELECT f.storage_key FROM images i
    LEFT JOIN files f ON f.id = i.file_id WHERE (i.identifier = ? OR i.legacy_identifier = ?)
    AND i.access_level_id <= 1 ORDER BY i.identifier = ? DESC LIMIT 1`,
  key, 'imagem@' + legacyIdentifier(slug), key).first<{ storage_key: string | null }>()
  const headers = new Headers({ 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff',
    'Content-Security-Policy': "sandbox; default-src 'none'; style-src 'unsafe-inline'" })
  // Determina o tipo pela extensão, como na rota dos arquivos estáticos.
  const mime = file?.storage_key ? mimeTypes[file.storage_key.split('.').pop()?.toLowerCase() ?? ''] : undefined
  if (file?.storage_key && mime) {
    const body = head ? null : await env.MEDIA.get(file.storage_key)
    const object = head ? await env.MEDIA.head(file.storage_key) : body
    if (object) {
      headers.set('Content-Type', mime)
      headers.set('Content-Length', String(object.size))
      return new Response(body?.body ?? null, { headers })
    }
  }
  return missingArticleImage(head)
}

const missingImageSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90"><title>Sem imagem</title><rect width="120" height="90" rx="6" fill="#edf0f4"/><g stroke="#64748b" stroke-width="3" fill="none"><rect x="36" y="14" width="48" height="36" rx="3"/><path d="m37 44 13-12 9 8 8-11 16 15"/><circle cx="72" cy="23" r="3"/></g><text x="60" y="72" fill="#334155" font-size="13" font-family="sans-serif" text-anchor="middle">Sem imagem</text></svg>'

export function missingArticleImage(head: boolean) {
  return new Response(head ? null : missingImageSvg, {
    headers: { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-store',
      'X-Content-Type-Options': 'nosniff', 'Content-Security-Policy': "sandbox; default-src 'none'" },
  })
}
