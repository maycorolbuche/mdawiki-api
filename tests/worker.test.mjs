import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { after, before, test } from 'node:test'
import { build } from 'esbuild'
import { Miniflare, convertV4MiniflareOptions } from 'miniflare'

const appKey = 'test-key-for-php-compatible-password-hashes'
const origin = 'https://dev.mda.wiki.br'
const legacyPassword = 'test-password-123'
let runtime
let db
const request = path => runtime.dispatchFetch('http://localhost' + path)

before(async () => {
  const built = await build({ entryPoints: ['src/index.ts'], bundle: true, format: 'esm', write: false, plugins: [{
    name: 'capture-smtp',
    setup(plugin) {
      plugin.onResolve({ filter: /^\.\/smtp$/ }, () => ({ path: 'smtp', namespace: 'test' }))
      plugin.onLoad({ filter: /^smtp$/, namespace: 'test' }, () => ({
        contents: `export const smtpConfigured = env => Boolean(env.SMTP_HOST);
          export const sendSmtp = (env, to, subject, text) =>
            env.MEDIA.put('test-mail/' + to, JSON.stringify({ subject, text }));`,
      }))
    },
  }] })
  runtime = new Miniflare(convertV4MiniflareOptions({
    modules: true, script: built.outputFiles[0].text, compatibilityDate: '2026-11-23', compatibilityFlags: ['nodejs_compat'],
    d1Databases: ['DB'], r2Buckets: ['MEDIA'],
    bindings: { FRONTEND_URL: 'https://mda.wiki.br', ALLOWED_ORIGINS: origin, APP_KEY: appKey, SMTP_HOST: 'smtp.example.test' },
  }))
  db = await runtime.getD1Database('DB')
  const schema = (await Promise.all(['0001_schema.sql', '0002_sessions.sql', '0003_visit_windows.sql', '0004_image_history.sql', '0005_page_edit_token.sql', '0006_password_resets.sql'].map(name => readFile('migrations/' + name, 'utf8')))).join('\n')
  const ddl = schema.replace(/^--.*$/gm, '')
  const triggers = ddl.match(/CREATE TRIGGER[\s\S]*?\nEND;/g) ?? []
  const statements = ddl.replace(/CREATE TRIGGER[\s\S]*?\nEND;/g, '').split(';').map(sql => sql.trim()).filter(Boolean)
  statements.push(...triggers)
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
  assert.equal(data.read_only, false)
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
  assert.equal((await runtime.dispatchFetch('http://localhost/v1/unsupported', { method: 'PUT' })).status, 501)
})

test('resets a legacy password, rotates the session and protects the private profile', async () => {
  await db.prepare('UPDATE users SET password_hash = ? WHERE id = 1').bind('p2$legacy').run()
  const initial = await request('/v1/auth/session')
  const anonymous = initial.headers.get('set-cookie').split(';')[0]
  const session = (await initial.json()).data
  const call = (path, cookie, csrf, body) => runtime.dispatchFetch('http://localhost' + path, {
    method: 'POST', headers: { Origin: origin, Cookie: cookie, 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const credentials = { email: 'private@example.test', password: legacyPassword, remember: true }
  assert.equal((await call('/v1/auth/login', anonymous, 'invalid', credentials)).status, 403)
  assert.equal((await call('/v1/auth/login', anonymous, session.csrf_token, credentials)).status, 401)
  assert.equal((await call('/v1/auth/forgot', anonymous, session.csrf_token, { email: credentials.email })).status, 200)
  const mail = await (await (await runtime.getR2Bucket('MEDIA')).get('test-mail/' + credentials.email)).json()
  const link = new URL(mail.text.split('\n').at(-1))
  assert.equal(link.pathname, '/p/redefinir-senha')
  credentials.password = legacyPassword
  const reset = { ...credentials, password_confirmation: credentials.password, token: link.searchParams.get('token') }
  assert.equal((await call('/v1/auth/reset', anonymous, session.csrf_token, reset)).status, 200)
  assert.equal((await call('/v1/auth/reset', anonymous, session.csrf_token, reset)).status, 422)
  const login = await call('/v1/auth/login', anonymous, session.csrf_token, credentials)
  assert.equal(login.status, 200, await login.clone().text())
  const active = login.headers.get('set-cookie')
  assert.match(active, /HttpOnly/i)
  assert.match(active, /Max-Age=2592000/i)
  const authenticated = active.split(';')[0]
  assert.notEqual(authenticated, anonymous)
  const { data } = await login.json()
  assert.equal(data.first_login, true)
  assert.equal(data.user.EMAIL, credentials.email)
  assert.equal(data.user.SENHA, undefined)
  assert.match(await db.prepare('SELECT password_hash FROM users WHERE id = 1').first('password_hash'), /^pbkdf2-sha256:100000:[a-f0-9]{64}:[a-f0-9]{64}$/)
  const profile = cookie => runtime.dispatchFetch('http://localhost/v1/profile', { headers: { Cookie: cookie } })
  assert.equal((await profile(anonymous)).status, 401)
  assert.equal((await profile(authenticated)).status, 200)
  const logout = await call('/v1/auth/logout', authenticated, data.csrf_token, {})
  assert.equal(logout.status, 200)
  assert.equal((await profile(authenticated)).status, 401)
  const retry = await call('/v1/auth/login', logout.headers.get('set-cookie').split(';')[0],
    (await logout.json()).data.csrf_token, credentials)
  assert.equal(retry.status, 200, await retry.clone().text())
  assert.equal((await retry.json()).data.first_login, false)
})

test('deduplicates visits across concurrent requests and updates the counter once', async () => {
  const session = await request('/v1/auth/session')
  const { data } = await session.json()
  const options = {
    method: 'POST', headers: { Origin: origin, Cookie: session.headers.get('set-cookie').split(';')[0],
      'X-CSRF-Token': data.csrf_token, 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug: 'Cães' }),
  }
  const before = await db.prepare('SELECT COUNT(*) AS count FROM page_visits').first('count')
  const responses = await Promise.all([1, 2].map(() => runtime.dispatchFetch('http://localhost/v1/visits', options)))
  assert.deepEqual(responses.map(response => response.status), [200, 200])
  const results = await Promise.all(responses.map(response => response.json()))
  assert.equal(results.filter(result => result.data.counted).length, 1)
  assert.equal(await db.prepare('SELECT COUNT(*) AS count FROM page_visits').first('count'), before + 1)
  assert.equal(await db.prepare('SELECT view_count FROM pages WHERE id = 41').first('view_count'), 1)
})

test('writes R2 images, queues linked articles and restores a former revision', async () => {
  const session = await request('/v1/auth/session')
  const { data } = await session.json()
  const headers = { Origin: origin, Cookie: session.headers.get('set-cookie').split(';')[0],
    'X-CSRF-Token': data.csrf_token, 'Content-Type': 'application/json' }
  const login = await runtime.dispatchFetch('http://localhost/v1/auth/login', {
    method: 'POST', headers, body: JSON.stringify({ email: 'private@example.test', password: legacyPassword }),
  })
  assert.equal(login.status, 200)
  headers.Cookie = login.headers.get('set-cookie').split(';')[0]
  headers['X-CSRF-Token'] = (await login.json()).data.csrf_token
  await db.prepare("INSERT INTO page_revisions (page_id, revision_number, slug, title, source, created_by, access_level_id) VALUES (41, 1, 'Cães', 'Público', 'Wiki', 1, 1), (41, 2, 'Cães', 'Restrito', 'Wiki interno', 1, 5)").run()
  const history = await runtime.dispatchFetch('http://localhost/v1/pages/C%C3%A3es/history', { headers })
  assert.equal(history.status, 200)
  assert.deepEqual((await history.json()).data.revisions.map(row => row.id), [1])
  assert.equal((await request('/v1/pages/C%C3%A3es/history')).status, 401)
  const preflight = await runtime.dispatchFetch('http://localhost/v1/images/Public', { method: 'OPTIONS',
    headers: { Origin: origin, 'Access-Control-Request-Method': 'PUT' } })
  assert.match(preflight.headers.get('Access-Control-Allow-Methods'), /PUT/)
  const update = body => runtime.dispatchFetch('http://localhost/v1/images/Public', {
    method: 'PUT', headers, body: JSON.stringify(body),
  })
  const file = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8NgAAAAASUVORK5CYII='
  const uploaded = await update({ base_version: '1', data: file })
  assert.equal(uploaded.status, 200, await uploaded.clone().text())
  const image = (await uploaded.json()).data
  assert.notEqual(image.version, '1')
  assert.equal(image.meta.width, 1)
  assert.equal(await db.prepare('SELECT page_id FROM page_rebuild_queue WHERE page_id = 41').first('page_id'), 41)
  assert.equal((await request(new URL(image.url).pathname)).status, 200)
  assert.equal((await update({ base_version: '1', clear: true })).status, 409)
  const cleared = await update({ base_version: image.version, clear: true })
  assert.equal(cleared.status, 200)
  assert.equal((await cleared.json()).data.url, null)
  const restore = await update({ base_version: '', revision_id: image.history[0].id })
  assert.equal(restore.status, 200)
  assert.equal((await restore.json()).data.version, '1')
})

test('creates and updates an article atomically, validates edits and restores a revision', async () => {
  const session = await request('/v1/auth/session')
  const { data } = await session.json()
  const headers = { Origin: origin, Cookie: session.headers.get('set-cookie').split(';')[0],
    'X-CSRF-Token': data.csrf_token, 'Content-Type': 'application/json' }
  const login = await runtime.dispatchFetch('http://localhost/v1/auth/login', {
    method: 'POST', headers, body: JSON.stringify({ email: 'private@example.test', password: legacyPassword }),
  })
  assert.equal(login.status, 200)
  headers.Cookie = login.headers.get('set-cookie').split(';')[0]
  headers['X-CSRF-Token'] = (await login.json()).data.csrf_token
  const call = (path, method, body) => runtime.dispatchFetch('http://localhost/v1/pages/Meu-Artigo' + path, {
    method, headers, body: body === undefined ? undefined : JSON.stringify(body),
  })
  const draft = await call('/editor', 'GET')
  assert.equal(draft.status, 200)
  assert.equal((await draft.json()).data.exists, false)
  const source = "== Introdução ==\n'''Texto''' [[Cães|Cães]] [[Futuro]] {{Categoria:Cultura}} <script>alert(1)</script>"
  const preview = await call('/preview', 'POST', { source })
  assert.equal(preview.status, 200, await preview.clone().text())
  const html = (await preview.json()).data.html
  assert.match(html, /<strong>Texto<\/strong>/)
  assert.doesNotMatch(html, /script|alert/)
  assert.equal((await call('/preview', 'POST', { source: '{{MacroLegada:Valor}}' })).status, 422)
  const created = await call('', 'PUT', { title: 'Meu artigo', source, base_version: '' })
  assert.equal(created.status, 200, await created.clone().text())
  const version = (await created.json()).data
  assert.equal(version.revision, 1)
  assert.equal(await db.prepare("SELECT COUNT(*) AS n FROM page_categories WHERE page_id = (SELECT id FROM pages WHERE identifier = 'meu-artigo')").first('n'), 1)
  assert.equal(await db.prepare("SELECT COUNT(*) AS n FROM page_missing_links WHERE href = '/futuro'").first('n'), 1)
  const changed = await call('', 'PUT', { title: 'Meu artigo', source: 'Nova versão', base_version: version.base_version })
  assert.equal(changed.status, 200, await changed.clone().text())
  const next = (await changed.json()).data
  assert.equal(next.revision, 2)
  assert.equal((await call('', 'PUT', { title: 'Meu artigo', source: 'Conflito', base_version: version.base_version })).status, 409)
  const restored = await call('/restore', 'POST', { revision: 1, base_version: next.base_version })
  assert.equal(restored.status, 200, await restored.clone().text())
  assert.equal((await restored.json()).data.revision, 3)
})


test('registers an inactive account, resends and activates with a one-use code', async () => {
  const session = await request('/v1/auth/session')
  const csrf = (await session.json()).data.csrf_token
  const headers = { Origin: origin, Cookie: session.headers.get('set-cookie').split(';')[0],
    'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }
  const call = (action, body, override = headers) => runtime.dispatchFetch('http://localhost/v1/auth/' + action, {
    method: 'POST', headers: override, body: JSON.stringify(body),
  })
  const email = 'registration@example.test'
  const password = 'test-password-42'
  const account = { email, name: 'Conta teste', password, password_confirmation: password }
  assert.equal((await call('register', account, { ...headers, 'X-CSRF-Token': 'invalid' })).status, 403)
  const created = await call('register', account)
  assert.equal(created.status, 200, await created.clone().text())
  assert.equal((await call('register', account)).status, 409)
  assert.equal((await call('login', { email, password })).status, 403)
  const bucket = await runtime.getR2Bucket('MEDIA')
  const getCode = async () => {
    const message = await (await bucket.get('test-mail/' + email)).json()
    return new URL(message.text.split('\n').at(-1)).searchParams.get('code')
  }
  const first = await getCode()
  assert.match(first, /^\d{6}$/)
  assert.equal((await call('resend', { email })).status, 200)
  const second = await getCode()
  assert.notEqual(first, second)
  assert.equal((await call('activate', { email, code: first })).status, 422)
  assert.equal((await call('activate', { email, code: second })).status, 200)
  assert.equal((await call('activate', { email, code: second })).status, 422)
  assert.equal((await call('login', { email, password })).status, 200)
})
