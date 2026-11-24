import { Hono } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { activeAccount, allowedAttempt, readSession, same } from './accounts'
import { identifier, legacyIdentifier } from './pages'
import { query, validSlug, type Env, type SqlValue } from './types'
import { renderWiki, WikiError, type RenderedWiki } from './wiki-renderer'

type Page = {
  id: number; title: string | null; source: string | null; slug: string; kind: string; identifier: string;
  rendered_html: string | null; updated_at: string | null; updated_by: number | null; worker_edit_token: string | null
}

const error = (code: string, message: string) => ({ error: { code, message } })
const notFound = error('not_found', 'Página não encontrada.')
const conflict = error('edit_conflict', 'Outra edição foi salva. Recarregue a página antes de salvar.')
const invalidPage = error('invalid_page', 'Informe um título e conteúdo de até 500 KB.')
const routes = new Hono<{ Bindings: Env }>()
export const articleWrites = routes
const editingDisabled = error('read_only_preview', 'Edição indisponível neste ambiente.')

export async function pageVersion(page: Pick<Page, 'id' | 'title' | 'source' | 'updated_at' | 'updated_by'> | null) {
  if (!page) return ''
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(
    JSON.stringify([page.id, page.title, page.source, page.updated_at, page.updated_by])))
  return Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('')
}

function lookup(db: D1Database, slug: string) {
  const key = identifier(slug)
  return query(db, `SELECT id, kind, slug, title, source, identifier, rendered_html, updated_at, updated_by, worker_edit_token
    FROM pages WHERE legacy_identifier NOT LIKE 'page@%' AND (identifier = ? OR legacy_identifier = ?)
    ORDER BY identifier = ? DESC LIMIT 1`, key, legacyIdentifier(slug), key).first<Page>()
}

const nameOf = (value: string) => value.normalize('NFC').replace(/[^\p{L}\p{M}\p{N}]+/gu, '-').replace(/^-|-$/g, '')
const trimField = (value: string) => [...value].slice(0, 250).join('')
const indexed = <T>(rows: T[]) => JSON.stringify(rows.map((row, i) => ({ ...row, position: i + 1 })))

/** Cada escrita derivada é condicionada ao token criado pela alteração principal no mesmo lote D1. */
function relations(db: D1Database, edit: string, wiki: RenderedWiki) {
  const page = '(SELECT id FROM pages WHERE worker_edit_token = ?)'
  const categories = Array.from(new Map(wiki.categories.map(title => [identifier(title), title])))
    .filter(([id]) => id.length >= 2 && id.length <= 250)
    .map(([id, title]) => ({ id, title: trimField(title), slug: nameOf(title) }))
  const targets = (rows: { name: string; fragment: string; label: string }[]) => indexed(rows
    .filter(row => identifier(row.name).length >= 2 && identifier(row.name).length <= 250)
    .map(row => ({ id: identifier(row.name), legacy: legacyIdentifier(row.name), fragment: trimField(row.fragment), label: trimField(row.label) })))
  const from = 'FROM json_each(?) j'
  const get = (key: string) => `json_extract(j.value, '$.${key}')`
  const target = `(SELECT id FROM pages WHERE identifier = ${get('id')}
    UNION ALL SELECT id FROM pages WHERE legacy_identifier = ${get('legacy')}
      AND NOT EXISTS (SELECT 1 FROM pages WHERE identifier = ${get('id')}) LIMIT 1)`
  const links = targets(wiki.links)
  const values = (table: string, cols: string[], json: string) => query(db,
    `INSERT INTO ${table}(page_id, position, ${cols.join(', ')})
    SELECT ${page}, ${get('position')}, ${cols.map(get).join(', ')} ${from}
    WHERE EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, json, edit)
  return [
    ...['page_categories', 'page_links', 'page_missing_links', 'page_external_links', 'page_redirects', 'page_includes',
      'page_templates', 'page_headings', 'page_media', 'page_videos'].map(table =>
      query(db, `DELETE FROM ${table} WHERE page_id = ${page}`, edit)),
    query(db, `INSERT INTO pages (legacy_identifier, identifier, slug, title, kind, source, rendered_html, plain_text,
       is_searchable, view_count, created_at, updated_at, rebuilt_at)
      SELECT '@category:' || ${get('id')}, ${get('id')}, ${get('slug')}, ${get('title')}, 'article', '', '', '',
        1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP ${from}
      WHERE EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?) ON CONFLICT(identifier) DO NOTHING`, indexed(categories), edit),
    query(db, `INSERT INTO page_categories (page_id, category_id, position)
      SELECT ${page}, p.id, ${get('position')} ${from} JOIN pages p ON p.identifier = ${get('id')}
      WHERE EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, indexed(categories), edit),
    query(db, `INSERT INTO page_links (page_id, target_page_id, position, fragment, link_text)
      SELECT ${page}, ${target}, ${get('position')}, ${get('fragment')}, ${get('label')} ${from}
      WHERE ${target} IS NOT NULL AND EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, links, edit),
    query(db, `INSERT INTO page_missing_links (page_id, href, position, fragment, link_text)
      SELECT ${page}, '/' || ${get('id')}, ${get('position')}, ${get('fragment')}, ${get('label')} ${from}
      WHERE ${target} IS NULL AND EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, links, edit),
    query(db, `INSERT INTO page_includes (page_id, target_page_id, position, section_anchor)
      SELECT ${page}, ${target}, ${get('position')}, ${get('fragment')} ${from}
      WHERE ${target} IS NOT NULL AND ${target} <> ${page}`,
      edit, targets(wiki.includes.map(link => ({ name: link.name, fragment: link.section, label: '' }))), edit),
    query(db, `INSERT INTO page_redirects (page_id, target_page_id, position)
      SELECT ${page}, ${target}, 1 ${from} WHERE ${target} <> ${page}`,
      edit, targets(wiki.redirect ? [{ name: wiki.redirect, label: '', fragment: '' }] : []), edit),
    values('page_external_links', ['href', 'link_text'], indexed(wiki.external.map(link => ({
      href: trimField(link.url), link_text: trimField(link.label),
    })))),
    values('page_templates', ['template_key', 'template_source'], indexed(wiki.templates.map(template => ({
      template_key: trimField(legacyIdentifier(template)), template_source: trimField('{{' + template + '}}'),
    })))),
    values('page_headings', ['title', 'heading_key', 'anchor', 'heading_level'], indexed(wiki.headings.map(heading => ({
      title: trimField(heading.title), heading_key: trimField(heading.key), anchor: trimField(heading.key), heading_level: heading.level,
    })))),
    values('page_videos', ['url'], indexed(wiki.videos.map(url => ({ url: trimField(url) })))),
    values('page_media', ['image_id', 'is_template'], indexed(wiki.images.filter(image => image.id !== null)
      .map(image => ({ image_id: image.id, is_template: Number(image.template) })))),
    query(db, `INSERT INTO page_links (page_id, target_page_id, position, fragment, link_text)
      SELECT m.page_id, p.id, m.position, m.fragment, m.link_text FROM page_missing_links m
      JOIN pages p ON m.href = '/' || p.identifier
      WHERE (p.worker_edit_token = ? OR p.identifier IN (SELECT ${get('id')} ${from}))
        AND EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, indexed(categories), edit),
    query(db, `DELETE FROM page_missing_links WHERE EXISTS (SELECT 1 FROM pages p
      WHERE href = '/' || p.identifier AND (p.worker_edit_token = ? OR p.identifier IN (SELECT ${get('id')} ${from})))
      AND EXISTS (SELECT 1 FROM pages WHERE worker_edit_token = ?)`, edit, indexed(categories), edit),
  ]
}

async function save(db: D1Database, page: Page | null, slug: string, title: string, source: string,
  author: number | null, wiki: RenderedWiki, rebuild: boolean) {
  const edit = crypto.randomUUID()
  const changed = !rebuild && (!page || page.title !== title || (page.source ?? '') !== source)
  const fields: SqlValue[] = [wiki.html, wiki.text, edit]
  const primary = page
    ? query(db, `UPDATE pages SET rendered_html = ?, plain_text = ?, worker_edit_token = ?, rebuilt_at = CURRENT_TIMESTAMP
        ${changed ? ', title = ?, source = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP' : ''}
      WHERE id = ? AND worker_edit_token IS ? RETURNING id, title, source, updated_at, updated_by`,
      ...fields, ...(changed ? [title, source, author] : []), page.id, page.worker_edit_token)
    : query(db, `INSERT INTO pages (rendered_html, plain_text, worker_edit_token, legacy_identifier, identifier, slug,
        title, source, kind, is_searchable, view_count, created_by, created_at, updated_by, updated_at, rebuilt_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'article', 1, 0, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
      ON CONFLICT(identifier) DO NOTHING RETURNING id, title, source, updated_at, updated_by`,
      ...fields, '@article:' + identifier(slug), identifier(slug), slug, title, source, author, author)
  const statements = [primary, query(db, 'DELETE FROM page_rebuild_queue WHERE page_id = (SELECT id FROM pages WHERE worker_edit_token = ?)', edit)]
  if (changed) statements.push(query(db, `INSERT INTO page_revisions
    (page_id, revision_number, slug, title, source, rendered_html, is_searchable, access_level_id, created_by, is_expired, created_at)
    SELECT p.id, COALESCE((SELECT MAX(revision_number) FROM page_revisions WHERE page_id = p.id), 0) + 1,
      p.slug, p.title, p.source, p.rendered_html, p.is_searchable, 1, ?, 0, CURRENT_TIMESTAMP
    FROM pages p WHERE p.worker_edit_token = ?`, author, edit))
  statements.push(...relations(db, edit, wiki))
  if (page?.rendered_html !== wiki.html) statements.push(query(db, `INSERT INTO page_rebuild_queue (page_id)
    SELECT DISTINCT i.page_id FROM page_includes i JOIN pages p ON p.id = i.target_page_id
    WHERE p.worker_edit_token = ? AND i.page_id <> p.id
    ON CONFLICT(page_id) DO UPDATE SET queued_at = CURRENT_TIMESTAMP`, edit))
  statements.push(query(db, `SELECT COALESCE(MAX(revision_number), 0) AS revision FROM page_revisions
    WHERE page_id = (SELECT id FROM pages WHERE worker_edit_token = ?)`, edit))
  const results = await db.batch(statements)
  const written = results[0].results[0] as Page | undefined
  if (!written) return null
  return { slug, revision: (results.at(-1)?.results[0] as { revision: number } | undefined)?.revision ?? 0, base_version: await pageVersion(written) }
}

routes.get('/pages/:slug/editor', async c => {
  if (c.env.ARTICLE_WRITES_ENABLED !== 'true') return c.json(editingDisabled, 501)
  c.header('Cache-Control', 'no-store')
  const user = await activeAccount(c, await readSession(c))
  if (!user) return c.json(error('login_required', 'Entre na sua conta para continuar.'), 401)
  if (user.role === 'reader') return c.json(error('permission_denied', 'Você não tem permissão para editar.'), 403)
  const requested = c.req.param('slug')
  const slug = nameOf(requested)
  if (!validSlug(requested) || requested.includes('@') || identifier(slug).length < 2) {
    return c.json(error('invalid_slug', 'Endereço inválido.'), 422)
  }
  const page = await lookup(c.env.DB, requested)
  if (page?.kind === 'image') return c.json(error('image_editor_unavailable', 'Use a rota de imagens.'), 501)
  return c.json({ data: { slug: page?.slug ?? slug, title: page?.title ?? slug.replaceAll('-', ' '), source: page?.source ?? '',
    base_version: await pageVersion(page), format: 'wiki', exists: Boolean(page), media: false } })
})

routes.on(['PUT', 'POST'], ['/pages/:slug', '/pages/:slug/:action{preview|restore|rebuild}'],
  bodyLimit({ maxSize: 3_020_000 }), async c => {
    if (c.env.ARTICLE_WRITES_ENABLED !== 'true') return c.json(editingDisabled, 501)
    c.header('Cache-Control', 'no-store')
    const action = c.req.param('action')
    if ((!action && c.req.method !== 'PUT') || (action && c.req.method !== 'POST')) {
      return c.json(error('method_not_allowed', 'Método não permitido.'), 405)
    }
    const session = await readSession(c)
    if (!c.req.header('Origin') || !session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) {
      return c.json(error('csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.'), 403)
    }
    const user = await activeAccount(c, session)
    if (!user) return c.json(error('login_required', 'Entre na sua conta para continuar.'), 401)
    if (user.role === 'reader' || action === 'rebuild' && user.role !== 'admin') {
      return c.json(error('permission_denied', 'Você não tem permissão para esta operação.'), 403)
    }
    if (!await allowedAttempt(c, 'article-user:' + user.ID, 100)) {
      return c.json(error('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
    }
    const requested = c.req.param('slug')
    const canonical = nameOf(requested)
    if (!validSlug(requested) || requested.includes('@') || identifier(canonical).length < 2) {
      return c.json(error('invalid_slug', 'Endereço inválido.'), 422)
    }
    const db = c.env.DB
    const page = await lookup(db, requested)
    if (page?.kind === 'image') return c.json(error('image_editor_unavailable', 'Use a rota de imagens.'), 501)
    const slug = page?.slug ?? canonical
    const body = await c.req.json<{ title?: unknown; source?: unknown; revision?: unknown; base_version?: unknown }>().catch(() => null)
    const rebuild = action === 'rebuild'
    if (!page && (rebuild || action === 'restore')) return c.json(notFound, 404)
    if (!rebuild && action !== 'preview' && body?.base_version !== await pageVersion(page)) return c.json(conflict, 409)
    let title = rebuild ? page?.title : body?.title
    let source = rebuild ? page?.source ?? '' : body?.source
    if (action === 'restore' && page) {
      const revision = Number(body?.revision)
      if (!Number.isSafeInteger(revision) || revision < 1) return c.json(error('invalid_revision', 'Revisão inválida.'), 422)
      const previous = await query(db, `SELECT title, source FROM page_revisions WHERE page_id = ? AND revision_number = ?
        AND COALESCE(access_level_id, 1) <= ? AND COALESCE(is_expired, 0) = 0`,
        page.id, revision, user.NIVEL).first<{ title: string; source: string }>()
      if (!previous) return c.json(error('not_found', 'Revisão não encontrada.'), 404)
      title = previous.title
      source = previous.source
    }
    const cleanTitle = typeof title === 'string' ? title.trim() : ''
    if (typeof source !== 'string' || new TextEncoder().encode(source).length > 500_000 ||
        action !== 'preview' && ([...cleanTitle].length < 2 || [...cleanTitle].length > 250)) {
      return c.json(invalidPage, 422)
    }
    try {
      const wiki = await renderWiki(db, source, slug, c.env.FRONTEND_URL.replace(/\/$/, ''))
      if (action === 'preview') return c.json({ data: { html: wiki.html.replaceAll('{{{url_diretorio}}}', new URL(c.req.url).origin + '/media/') } })
      const result = await save(db, page, slug, cleanTitle, source, user.ID, wiki, rebuild)
      return result ? c.json({ data: result }) : c.json(conflict, 409)
    } catch (cause) {
      if (cause instanceof WikiError) return c.json(error(cause.code, cause.message), 422)
      throw cause
    }
  })

/** Reprocessa em lotes pequenos e mantém uma página com erro na fila para diagnóstico. */
export async function rebuildQueuedArticles(env: Env) {
  if (env.ARTICLE_WRITES_ENABLED !== 'true') return
  const db = env.DB
  const rows = await query(db, `SELECT p.* FROM page_rebuild_queue q JOIN pages p ON p.id = q.page_id
    WHERE p.kind <> 'image' AND p.legacy_identifier NOT LIKE 'page@%' ORDER BY q.queued_at, q.page_id LIMIT 5`).all<Page>()
  for (const page of rows.results) {
    try {
      const source = page.source ?? ''
      const wiki = await renderWiki(db, source, page.slug, env.FRONTEND_URL.replace(/\/$/, ''))
      await save(db, page, page.slug, page.title ?? page.slug, source, null, wiki, true)
    } catch {
      console.error(JSON.stringify({ event: 'page_rebuild_failed', page_id: page.id }))
      await query(db, 'UPDATE page_rebuild_queue SET queued_at = CURRENT_TIMESTAMP WHERE page_id = ?', page.id).run()
    }
  }
}
