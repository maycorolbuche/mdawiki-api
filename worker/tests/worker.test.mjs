import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { after, before, test } from 'node:test'
import { build } from 'esbuild'
import { Miniflare, convertV4MiniflareOptions } from 'miniflare'

let runtime
let db
const request = path => runtime.dispatchFetch('http://localhost' + path)

before(async () => {
  const built = await build({ entryPoints: ['src/index.ts'], bundle: true, format: 'esm', write: false })
  runtime = new Miniflare(convertV4MiniflareOptions({
    modules: true, script: built.outputFiles[0].text, compatibilityDate: '2026-11-23',
    d1Databases: ['DB'], r2Buckets: ['MEDIA'],
    bindings: { FRONTEND_URL: 'https://mda.wiki.br', ALLOWED_ORIGINS: 'https://dev.mda.wiki.br' },
  }))
  db = await runtime.getD1Database('DB')
  const schema = await readFile('migrations/0001_schema.sql', 'utf8')
  const statements = schema.replace(/^--.*$/gm, '').split(';').map(sql => sql.trim()).filter(Boolean)
  await db.batch(statements.map(sql => db.prepare(sql)))
  await db.batch([
    db.prepare("INSERT INTO pages (id, slug, title, identifier, legacy_identifier, kind, source, plain_text, rendered_html, is_searchable) VALUES (41, 'Cães', 'Cães', 'caes', 'caes', 'article', 'Wiki', 'Conteúdo', '<p>Conteúdo</p>', 1), (42, 'Privado', 'Privado', 'privado', 'privado', 'article', 'Wiki', 'Privado', '', 0)"),
    db.prepare("INSERT INTO files (id, sha256, storage_key, mime_type, size_bytes) VALUES (1, 'hash', 'articles/public.png', 'image/png', 3), (2, 'hash2', 'articles/private.png', 'image/png', 4)"),
    db.prepare("INSERT INTO images (id, identifier, slug, title, meta, file_id, access_level_id) VALUES (1, 'public', 'Public', 'Public', '{}', 1, 1), (2, 'private', 'Private', 'Private', '{}', 2, 5)"),
    db.prepare('INSERT INTO page_media (page_id, image_id, position) VALUES (41, 1, 1)'),
    db.prepare("INSERT INTO users (id, name, email, password_hash, is_active, public_id, avatar_filename) VALUES (1, 'Editora', 'private@example.test', 'secret', 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'avatar.png')"),
    db.prepare("INSERT INTO page_visits (page_id, visited_at) VALUES (41, unixepoch())"),
  ])
  const bucket = await runtime.getR2Bucket('MEDIA')
  await bucket.put('articles/public.png', 'png')
  await bucket.put('articles/private.png', 'priv')
})

after(async () => { await runtime?.dispose() })

test('exports public pages and resolves slugs and covers without changing IDs', async () => {
  const response = await request('/v1/pages/C%C3%A3es')
  assert.equal(response.status, 200)
  const { data } = await response.json()
  assert.equal(data.id, '41')
  assert.equal(data.image, 'http://localhost/media/articles/public.png')
  assert.equal(data.read_only, true)
  assert.equal((await request('/v1/pages/Privado')).status, 404)
  const list = await (await request('/v1/pages?limit=1')).json()
  assert.deepEqual(list.data.map(page => page.id), ['41'])
  assert.equal(list.next_cursor, null)
})

test('returns search, statistics and visit rankings from D1', async () => {
  const { data } = await (await request('/v1/search?q=Conte')).json()
  assert.deepEqual(data.items.map(row => row.id), ['41'])
  const response = await request('/v1/home')
  assert.equal(response.status, 200)
  const { data: home } = await response.json()
  assert.equal(home.top_24h[0].views, 1)
  assert.equal(home.statistics.pages, 1)
})

test('serves authorized R2 objects only and excludes credentials from profiles', async () => {
  const response = await request('/media/articles/public.png')
  assert.equal(response.status, 200)
  assert.equal(await response.text(), 'png')
  assert.equal((await request('/media/articles/private.png')).status, 404)
  const { data } = await (await request('/v1/public-users/' + 'a'.repeat(32))).json()
  assert.equal(data.name, 'Editora')
  assert.equal(data.email, undefined)
  assert.equal(data.password_hash, undefined)
})

test('rejects invalid foreign keys and rolls back all statements in the failed batch', async () => {
  await assert.rejects(db.batch([
    db.prepare('UPDATE pages SET title = ? WHERE id = 41').bind('Failed'),
    db.prepare('INSERT INTO page_media (page_id, image_id, position) VALUES (41, 999, 1)'),
  ]))
  assert.equal(await db.prepare('SELECT title FROM pages WHERE id = 41').first('title'), 'Cães')
})

test('allows only configured cross-origin requests and blocks writes in the preview', async () => {
  const cors = await runtime.dispatchFetch('http://localhost/v1/pages', { headers: { Origin: 'https://dev.mda.wiki.br' } })
  assert.equal(cors.headers.get('access-control-allow-origin'), 'https://dev.mda.wiki.br')
  assert.equal((await runtime.dispatchFetch('http://localhost/v1/pages', { headers: { Origin: 'https://other.test' } })).status, 403)
  assert.equal((await runtime.dispatchFetch('http://localhost/v1/pages/cães', { method: 'PUT' })).status, 501)
})
