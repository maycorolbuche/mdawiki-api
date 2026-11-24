import { Hono, type Context } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { getCookie, setCookie } from 'hono/cookie'
import { findPage, identifier, legacyIdentifier, mediaUrl, publicPage } from './pages'
import { query, validSlug, type Env } from './types'

type Api = { Bindings: Env }
type RequestContext = Context<Api>
type Session = { token_hash: string; csrf_token: string; user_id: number | null; password_fingerprint: string | null }
type Account = { ID: number; NOME: string | null; EMAIL: string; SENHA: string; FOTO: string | null; ATIVO: 'S' | 'N'; role: 'reader' | 'user' | 'admin'; NIVEL: number }
const accountFields = `id AS ID, password_hash AS SENHA, avatar_filename AS FOTO, email AS EMAIL,
  name AS NOME, display_name AS APELIDO, role, CASE role WHEN 'reader' THEN 0 WHEN 'admin' THEN 5 ELSE 1 END AS NIVEL,
  birth_date AS DATA_NASC, city AS CIDADE, state_code AS ESTADO, club AS CLUBE,
  association AS ASSOCIACAO, club_type AS TIPO_CLUBE, biography AS BIOGRAFIA,
  created_at AS DT_CADASTRO, updated_at AS DT_ALTERACAO, CASE is_active WHEN 1 THEN 'S' ELSE 'N' END AS ATIVO`
const cookie = 'mdawiki_worker_session'
const encoder = new TextEncoder()
const hex = (bytes: ArrayBuffer | Uint8Array) => Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('')
const token = () => hex(crypto.getRandomValues(new Uint8Array(32)))
const now = () => Math.floor(Date.now() / 1000)
const digest = async (value: string, algorithm = 'SHA-256') => hex(await crypto.subtle.digest(algorithm, encoder.encode(value)))
export const same = (a: string, b: string) => a.length === b.length && (crypto.subtle as SubtleCrypto & { timingSafeEqual(a: Uint8Array, b: Uint8Array): boolean }).timingSafeEqual(encoder.encode(a), encoder.encode(b))
const failure = (code: string, message: string) => ({ error: { code, message } })
const csrfFailure = failure('csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.')
const loginRequired = failure('login_required', 'Entre na sua conta para continuar.')
const invalidCredentials = failure('invalid_credentials', 'E-mail ou senha inválidos. Se ainda usa a senha antiga, clique em Esqueci minha senha.')

function profile(user: Account, url: string) {
  const { SENHA, FOTO, ...data } = user
  return { ...data, photo: FOTO ? mediaUrl(new URL(url).origin, 'users/' + FOTO) : null }
}

/** Guarda no D1 apenas o hash do cookie, consultando a expiração em cada requisição. */
export async function readSession(c: RequestContext) {
  const value = getCookie(c, cookie)
  if (!value || !/^[a-f0-9]{64}$/.test(value)) return null
  return query(c.env.DB, 'SELECT * FROM worker_sessions WHERE token_hash = ? AND expires_at > ?',
    await digest(value), now()).first<Session>()
}

export async function activeAccount(c: RequestContext, session: Session | null) {
  if (!session?.user_id) return null
  const user = await query(c.env.DB, `SELECT ${accountFields} FROM users WHERE id = ? AND is_active = 1`,
    session.user_id).first<Account>()
  return user?.SENHA && same(await digest(user.SENHA), session.password_fingerprint ?? '') ? user : null
}

function writeCookie(c: RequestContext, value: string, maxAge?: number) {
  const secure = new URL(c.req.url).protocol === 'https:'
  setCookie(c, cookie, value, { path: '/', httpOnly: true, secure, sameSite: secure ? 'None' : 'Lax', maxAge })
}

/** Limita a emissão de sessões e tentativas de login sem armazenar e-mail ou IP em texto puro. */
export async function allowedAttempt(c: RequestContext, scope: string, max: number) {
  const result = await query(c.env.DB, `INSERT INTO worker_rate_limits(scope, window, attempts) VALUES (?, ?, 1)
    ON CONFLICT(scope, window) DO UPDATE SET attempts = attempts + 1 WHERE attempts < ? RETURNING attempts`,
    await digest(scope), Math.floor(now() / 3600), max).first()
  return result !== null
}

const passwordPrefix = 'pbkdf2-sha256:100000:'

async function derivePassword(password: string, salt: string, appKey: string) {
  const key = await crypto.subtle.importKey('raw', encoder.encode(appKey), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const input = await crypto.subtle.sign('HMAC', key, encoder.encode('password:v1:' + password))
  const material = await crypto.subtle.importKey('raw', input, 'PBKDF2', false, ['deriveBits'])
  return hex(await crypto.subtle.deriveBits({
    name: 'PBKDF2', hash: 'SHA-256', salt: encoder.encode(salt), iterations: 100_000,
  }, material, 256))
}

/** Usa o teto suportado pelo Worker, com salt aleatório e APP_KEY como pepper. */
export async function passwordHash(password: string, appKey: string) {
  const salt = token()
  return passwordPrefix + salt + ':' + await derivePassword(password, salt, appKey)
}

export const accounts = new Hono<Api>()
for (const path of ['/auth/*', '/profile', '/view/*', '/visits']) {
  accounts.use(path, async (c, next) => {
    c.header('Cache-Control', 'no-store')
    await next()
  })
}
accounts.use('/auth/login', bodyLimit({ maxSize: 8192, onError: c => c.json(failure('body_too_large', 'Solicitação muito grande.'), 413) }))

accounts.get('/auth/session', async c => {
  const session = await readSession(c)
  const user = await activeAccount(c, session)
  if (session && (session.user_id === null || user)) {
    return c.json({ data: { user: user ? profile(user, c.req.url) : null, csrf_token: session.csrf_token } })
  }
  if (!await allowedAttempt(c, 'session:' + (c.req.header('CF-Connecting-IP') ?? 'local'), 150)) {
    return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const value = token()
  const csrf = token()
  await query(c.env.DB, 'INSERT INTO worker_sessions(token_hash, csrf_token, expires_at) VALUES (?, ?, ?)',
    await digest(value), csrf, now() + 3600).run()
  writeCookie(c, value)
  return c.json({ data: { user: null, csrf_token: csrf } })
})

accounts.post('/auth/login', async c => {
  if (!c.req.header('Origin')) return c.json(failure('origin_required', 'Origem da solicitação ausente.'), 403)
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) return c.json(csrfFailure, 403)
  const body = await c.req.json<{ email?: unknown; password?: unknown; remember?: unknown }>().catch(() => null)
  const email = typeof body?.email === 'string' ? body.email.trim().toLowerCase() : ''
  const password = body?.password
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || encoder.encode(email).length > 150) {
    return c.json(failure('invalid_email', 'Informe um e-mail válido.'), 422)
  }
  if (typeof password !== 'string' || !password || encoder.encode(password).length > 1024) {
    return c.json(failure('invalid_password', 'Senha inválida.'), 422)
  }
  const appKey = c.env.APP_KEY ?? ''
  if (encoder.encode(appKey).length < 32) {
    return c.json(failure('auth_not_configured', 'Login indisponível.'), 503)
  }
  const ip = c.req.header('CF-Connecting-IP') ?? 'local'
  if (!await allowedAttempt(c, 'login-ip:' + ip, 20) || !await allowedAttempt(c, 'login-email:' + email, 20)) {
    return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const { results } = await query(c.env.DB, `SELECT ${accountFields} FROM users WHERE email = ? LIMIT 2`, email).all<Account>()
  const user = results.length === 1 ? results[0] : null
  const stored = user?.SENHA ?? ''
  const encoded = /^pbkdf2-sha256:100000:([a-f0-9]{64}):([a-f0-9]{64})$/.exec(stored)
  const derived = await derivePassword(password, encoded?.[1] ?? '0'.repeat(64), appKey)
  if (!user || !encoded || !same(derived, encoded[2])) {
    return c.json(invalidCredentials, 401)
  }
  if (user.ATIVO !== 'S') return c.json(failure('inactive_account', 'Ative sua conta pelo código enviado por e-mail.'), 403)

  const value = token()
  const hash = await digest(value)
  const csrf = token()
  const lifetime = body?.remember === true ? 2592000 : 43200
  const db = c.env.DB
  // Todas as gravações ficam em um lote atômico. A senha e a sessão original precisam continuar válidas.
  const operations = await db.batch([
    query(db, `INSERT INTO worker_sessions(token_hash, csrf_token, user_id, password_fingerprint, expires_at)
      SELECT ?, ?, id, ?, ? FROM users WHERE id = ? AND is_active = 1 AND password_hash = ?
      AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ? AND expires_at > ?)`,
    hash, csrf, await digest(stored), now() + lifetime, user.ID, stored, session.token_hash, now()),
    query(db, `UPDATE users SET has_logged_in = 1, last_seen_at = CURRENT_TIMESTAMP
      WHERE id = ? AND COALESCE(has_logged_in, 0) = 0 AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ?)`,
    user.ID, hash),
    query(db, `UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?
      AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ?)`, user.ID, hash),
    query(db, `DELETE FROM worker_sessions WHERE token_hash = ?
      AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ?)`, session.token_hash, hash),
  ])
  if (!operations[0].meta.changes) return c.json(csrfFailure, 403)
  writeCookie(c, value, lifetime > 43200 ? lifetime : undefined)
  return c.json({ data: { user: profile(user, c.req.url), csrf_token: csrf, first_login: operations[1].meta.changes === 1 } })
})

accounts.post('/auth/logout', async c => {
  if (!c.req.header('Origin')) return c.json(failure('origin_required', 'Origem da solicitação ausente.'), 403)
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) return c.json(csrfFailure, 403)
  const value = token()
  const csrf = token()
  await c.env.DB.batch([
    query(c.env.DB, 'DELETE FROM worker_sessions WHERE token_hash = ?', session.token_hash),
    query(c.env.DB, 'INSERT INTO worker_sessions(token_hash, csrf_token, expires_at) VALUES (?, ?, ?)',
      await digest(value), csrf, now() + 3600),
  ])
  writeCookie(c, value)
  return c.json({ data: { user: null, csrf_token: csrf } })
})

accounts.get('/profile', async c => {
  const user = await activeAccount(c, await readSession(c))
  return user ? c.json({ data: profile(user, c.req.url) }) : c.json(loginRequired, 401)
})

accounts.get('/view/:slug', async c => {
  const user = await activeAccount(c, await readSession(c))
  if (!user) return c.json(loginRequired, 401)
  const slug = c.req.param('slug')
  if (!validSlug(slug)) return c.json(failure('invalid_slug', 'Endereço inválido.'), 422)
  const page = await findPage(c.env.DB, new URL(c.req.url).origin, c.env.FRONTEND_URL.replace(/\/$/, ''), slug)
  return page ? c.json({ data: { ...page, is_public: true } }) : c.json(failure('not_found', 'Página não encontrada.'), 404)
})

accounts.post('/visits', bodyLimit({ maxSize: 4096, onError: c => c.json(failure('body_too_large', 'Solicitação muito grande.'), 413) }), async c => {
  if (!c.req.header('Origin')) return c.json(failure('origin_required', 'Origem da solicitação ausente.'), 403)
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) return c.json(csrfFailure, 403)
  const body = await c.req.json<{ slug?: unknown }>().catch(() => null)
  const slug = body?.slug
  if (typeof slug !== 'string' || !validSlug(slug)) return c.json(failure('invalid_slug', 'Endereço inválido.'), 422)
  const timestamp = now()
  const db = c.env.DB
  const key = identifier(slug)
  const page = await query(db, `SELECT CAST(p.id AS TEXT) AS id FROM pages p WHERE ${publicPage}
    AND (p.identifier = ? OR p.legacy_identifier = ?) ORDER BY p.identifier = ? DESC LIMIT 1`,
    key, legacyIdentifier(slug), key).first<{ id: string }>()
  if (!page) return c.json(failure('not_found', 'Página não encontrada.'), 404)
  // O UPSERT e seus triggers deduplicam visitas concorrentes na mesma janela de 30 segundos.
  const result = await query(db, `INSERT INTO worker_visit_windows (session_hash, page_id, visited_at)
    SELECT s.token_hash, CAST(? AS INTEGER), ? FROM worker_sessions s
    WHERE s.token_hash = ? AND s.expires_at > ?
    ON CONFLICT (session_hash, page_id) DO UPDATE SET visited_at = excluded.visited_at
    WHERE worker_visit_windows.visited_at <= excluded.visited_at - 30 RETURNING page_id`,
    page.id, timestamp, session.token_hash, timestamp).first()
  return c.json({ data: { counted: result !== null } })
})

/** Expira sessões, limites e a janela histórica de visitas. */
export async function purgeExpiredData(db: D1Database, timestamp: number) {
  await db.batch([
    query(db, 'DELETE FROM worker_sessions WHERE expires_at <= ?', timestamp),
    query(db, 'DELETE FROM worker_password_resets WHERE expires_at <= ?', timestamp),
    query(db, 'DELETE FROM worker_rate_limits WHERE window < ?', Math.floor(timestamp / 3600)),
    query(db, 'DELETE FROM page_visits WHERE visited_at < ?', timestamp - 31 * 86400),
  ])
}
