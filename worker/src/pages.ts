import { decodeHTML } from 'entities'
import { query } from './types'

export const publicPage = "p.is_searchable = 1 AND length(p.identifier) > 1 AND p.legacy_identifier NOT LIKE 'page@%'"
const card = 'CAST(p.id AS TEXT) AS id, p.slug, COALESCE(p.title, p.slug) AS title'
const cover = `(SELECT f.storage_key FROM page_media m
  JOIN images i ON i.id = m.image_id JOIN files f ON f.id = i.file_id
  WHERE m.page_id = p.id AND COALESCE(m.is_template, 0) = 0 AND i.access_level_id <= 1
  ORDER BY m.position, m.id LIMIT 1)`

export const identifier = (slug: string) => slug.normalize('NFC').toLowerCase()
  .replace(/[^\p{L}\p{M}\p{N}]+/gu, '-').replace(/^-|-$/g, '')
  .normalize('NFD').replace(/\p{M}/gu, '').normalize('NFC')
export const legacyIdentifier = (slug: string) => slug.replace(/[áàãâäéêèëíìîïóòôõöúùûüçñ]/gi,
  letter => letter.normalize('NFD').replace(/\p{M}/gu, '')).replace(/['"]/g, '').replace(/ /g, '_').toLowerCase()
export const mediaUrl = (base: string, key: string | null) =>
  key ? base + '/media/' + key.split('/').map(encodeURIComponent).join('/') : null

type Card = { id: string; slug: string; title: string }
type Page = Card & {
  html: string | null; text: string | null; image: string | null; updated_at: string | null;
  categories: string; mentions: string; related_pages: string; category_members: string;
  missing_links: string; redirect_slug: string | null;
}

/** Usa subconsultas agrupadas para evitar uma subrequisição D1 por relação e por artigo. */
function relation(from: string, condition: string, order: string, limit = '') {
  return `(SELECT json_group_array(json_object('id', id, 'slug', slug, 'title', title))
    FROM (SELECT DISTINCT ${card} FROM ${from} WHERE ${condition} AND ${publicPage} ORDER BY ${order} ${limit}))`
}

const pageQuery = `SELECT CAST(article.id AS TEXT) AS id, article.slug, article.title,
  article.rendered_html AS html, article.plain_text AS text, COALESCE(article.rebuilt_at, article.updated_at) AS updated_at,
  ${cover.replace('m.page_id = p.id', 'm.page_id = article.id')} AS image,
  ${relation('page_categories c JOIN pages p ON p.id = c.category_id', 'c.page_id = article.id', 'c.position, c.id')} AS categories,
  ${relation('page_links l JOIN pages p ON p.id = l.page_id', 'l.target_page_id = article.id AND p.id <> article.id', 'title, p.id')} AS mentions,
  ${relation('page_categories c JOIN pages p ON p.id = c.page_id', 'c.category_id = article.id', 'title, p.id')} AS category_members,
  ${relation('pages p', 'p.id <> article.id AND EXISTS (SELECT 1 FROM page_categories c JOIN page_categories r USING (category_id) WHERE c.page_id = article.id AND r.page_id = p.id)', 'random()', 'LIMIT 5')} AS related_pages,
  (SELECT json_group_array(json_object('id', href, 'slug', substr(href, 2), 'title', title))
    FROM (SELECT href, COALESCE(MIN(NULLIF(link_text, '')), substr(href, 2)) AS title
          FROM page_missing_links WHERE page_id = article.id GROUP BY href ORDER BY MIN(position))) AS missing_links,
  (SELECT p.slug FROM page_redirects r JOIN pages p ON p.id = r.target_page_id
    WHERE r.page_id = article.id AND ${publicPage} ORDER BY r.position, r.id LIMIT 1) AS redirect_slug
  FROM pages article WHERE ${publicPage.replaceAll('p.', 'article.')}`

/** Formata o HTML importado sem executar o renderizador wiki durante a leitura. */
function present(row: Page, api: string, frontend: string) {
  const { id, slug, updated_at, redirect_slug } = row
  const html = (row.html ?? '')
    .replaceAll('{{{url_site}}}', frontend + '/')
    .replaceAll('{{{url_diretorio}}}', api + '/media/')
    .replace(/https?:\/\/api\.mda\.wiki\.br\/media\//gi, api + '/media/')
  const description = decodeHTML((row.text ?? '').replace(/<[^>]*>/g, '')).trim()
  return {
    id, slug, title: decodeHTML(row.title ?? slug), html, updated_at, redirect_slug,
    description: [...description].slice(0, 240).join(''), image: mediaUrl(api, row.image),
    canonical_url: frontend + '/' + encodeURIComponent(slug) + '/',
    categories: JSON.parse(row.categories), mentions: JSON.parse(row.mentions),
    category_members: JSON.parse(row.category_members), related_pages: JSON.parse(row.related_pages),
    missing_links: JSON.parse(row.missing_links), study_material_url: null, image_usage: [], read_only: true,
  }
}

export async function listPages(db: D1Database, api: string, frontend: string, limit: number, after: string) {
  const { results } = await query(db, pageQuery + ' AND article.id > CAST(? AS INTEGER) ORDER BY article.id LIMIT ?',
    after || '0', limit + 1).all<Page>()
  return {
    data: results.slice(0, limit).map(row => present(row, api, frontend)),
    next_cursor: results.length > limit ? results[limit - 1].id : null,
  }
}

export async function findPage(db: D1Database, api: string, frontend: string, slug: string) {
  const key = identifier(slug)
  const row = await query(db, pageQuery + ` AND (article.identifier = ? OR article.legacy_identifier = ?)
    ORDER BY article.identifier = ? DESC LIMIT 1`, key, legacyIdentifier(slug), key).first<Page>()
  return row && present(row, api, frontend)
}

/** Pagina a busca, tratando caracteres especiais como texto literal. */
export async function searchPages(db: D1Database, term: string, limit: number, offset: number) {
  if (!term) return { items: [], next_offset: null }
  const { results } = await query(db, `SELECT ${card}, COALESCE(p.updated_at, p.created_at) AS updated_at,
    COALESCE(substr(p.plain_text, 1, 240), '') AS description, NULL AS image
    FROM pages p WHERE ${publicPage} AND (instr(lower(p.title), lower(?)) > 0 OR instr(lower(p.plain_text), lower(?)) > 0)
    ORDER BY p.title COLLATE NOCASE, p.id LIMIT ? OFFSET ?`, term, term, limit + 1, offset).all()
  return { items: results.slice(0, limit), next_offset: results.length > limit ? offset + limit : null }
}

export const statistics = (db: D1Database) => query(db, `SELECT COUNT(*) AS pages,
  (SELECT COUNT(*) FROM images WHERE access_level_id <= 1) AS images,
  COALESCE(SUM(p.view_count), 0) AS views FROM pages p WHERE ${publicPage}`).first()

/** Agrupa as janelas de visitas no banco e mantém as chaves do contrato da home. */
export async function home(db: D1Database, api: string, now = Math.floor(Date.now() / 1000)) {
  const fields = `${card}, COALESCE(p.updated_at, p.created_at) AS updated_at,
    COALESCE(substr(p.plain_text, 1, 240), '') AS description, ${cover} AS image`
  const eligible = `${publicPage} AND trim(p.source, char(9, 10, 11, 12, 13, 32)) <> ''`
  const base = `SELECT ${fields} FROM pages p WHERE ${eligible}`
  const windows = [1, 7, 30].map(days => query(db, `SELECT ${fields}, v.views FROM pages p JOIN
    (SELECT page_id, COUNT(*) AS views FROM page_visits WHERE visited_at BETWEEN ? AND ? GROUP BY page_id) v
    ON v.page_id = p.id WHERE ${eligible} ORDER BY v.views DESC, p.id LIMIT 10`, now - days * 86400, now))
  const [stats, groups] = await Promise.all([statistics(db), db.batch<{ image: string | null }>([
    query(db, base + ' ORDER BY random() LIMIT 3'),
    query(db, base + ' ORDER BY updated_at DESC, p.id LIMIT 10'),
    query(db, base + ' ORDER BY p.view_count DESC, p.id LIMIT 10'),
    ...windows,
    query(db, `SELECT m.href AS id, substr(m.href, 2) AS slug,
      COALESCE(MIN(NULLIF(m.link_text, '')), replace(substr(m.href, 2), '-', ' ')) AS title
      FROM page_missing_links m JOIN pages p ON p.id = m.page_id
      WHERE ${publicPage} AND NOT EXISTS (SELECT 1 FROM pages WHERE identifier = substr(m.href, 2))
      GROUP BY m.href ORDER BY random() LIMIT 10`),
  ])])
  const [random, recent, popular, top_24h, top_week, top_month] = groups.slice(0, 6)
    .map(group => group.results.map(row => ({ ...row, image: mediaUrl(api, row.image) })))
  return { statistics: stats, random, recent, popular, top_24h, top_today: top_24h, top_week, top_month,
    missing: groups[6].results }
}
