import { identifier, legacyIdentifier, mediaUrl } from './pages'
import { query } from './types'

/** Expõe apenas os campos públicos da conta ativa. */
export async function publicUser(db: D1Database, api: string, id: string) {
  if (!/^[a-f0-9]{32}$/.test(id)) return null
  const row = await query(db, `SELECT name, display_name, birth_date, city, state_code, club, association,
    club_type, biography, avatar_filename FROM users WHERE public_id = ? AND is_active = 1`, id)
    .first<{ avatar_filename: string | null }>()
  if (!row) return null
  const { avatar_filename, ...user } = row
  return { ...user, photo: avatar_filename ? mediaUrl(api, 'users/' + avatar_filename) : null }
}

type Image = { id: number; slug: string; title: string; meta: string; file_id: number | null; storage_key: string | null }

/** Retorna a imagem e o histórico filtrado pelo mesmo nível de publicação. */
export async function publicImage(db: D1Database, api: string, slug: string) {
  const name = slug.replace(/^imagem@/i, '')
  const key = identifier(name)
  const image = await query(db, `SELECT i.id, i.slug, i.title, i.meta, i.file_id, f.storage_key
    FROM images i LEFT JOIN files f ON f.id = i.file_id
    WHERE i.access_level_id <= 1 AND (i.identifier = ? OR i.legacy_identifier = ?)
    ORDER BY i.identifier = ? DESC LIMIT 1`, key, 'imagem@' + legacyIdentifier(name), key).first<Image>()
  if (!image) return null
  const [pages, history] = await db.batch<{ storage_key: string }>([
    query(db, `SELECT DISTINCT p.id, p.slug, COALESCE(p.title, p.slug) AS title
      FROM page_media m JOIN pages p ON p.id = m.page_id
      WHERE m.image_id = ? AND p.is_searchable = 1 AND p.legacy_identifier NOT LIKE 'page@%' ORDER BY title, p.id`, image.id),
    query(db, `SELECT r.id, r.created_at, f.storage_key, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.name, '')) AS author,
      CASE WHEN u.is_active = 1 THEN u.public_id END AS author_profile_id
      FROM image_revisions r JOIN files f ON f.id = r.file_id LEFT JOIN users u ON u.id = r.created_by
      WHERE r.image_id = ? AND r.access_level_id <= 1 ORDER BY r.created_at DESC, r.id DESC`, image.id),
  ])
  return {
    slug: image.slug, title: image.title, url: mediaUrl(api, image.storage_key),
    meta: JSON.parse(image.meta), version: String(image.file_id ?? ''), pages: pages.results,
    history: history.results.map(({ storage_key, ...revision }) => ({ ...revision, url: mediaUrl(api, storage_key) })),
  }
}
