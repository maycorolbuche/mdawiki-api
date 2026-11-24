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
