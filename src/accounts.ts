import type { SessionStage } from './diagnostics'
import { decodeUpload, formats, readImage } from './image-upload'
import { notifyProfile, securityMail } from './wiki-mail'
import { smtpConfigured } from './smtp'
import { Hono, type Context, type Handler } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { getCookie, setCookie } from 'hono/cookie'
import { findPage, identifier, legacyIdentifier, mediaUrl, publicPage } from './pages'
import { query, validSlug, type Env } from './types'

type Api = { Bindings: Env; Variables: { sessionStage: SessionStage; profileSession: Session; profileAccount: Account } }
type RequestContext = Pick<Context<{ Bindings: Env }>, 'env' | 'req'>
type Session = { token_hash: string; csrf_token: string; user_id: number | null; password_fingerprint: string | null; expires_at: number }
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
export async function readSession<T extends { Bindings: Env }>(c: Context<T>) {
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

function writeCookie<T extends { Bindings: Env }>(c: Context<T>, value: string, maxAge?: number) {
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

async function verifyPassword(password: string, stored: string, appKey: string) {
  const encoded = /^pbkdf2-sha256:100000:([a-f0-9]{64}):([a-f0-9]{64})$/.exec(stored)
  const derived = await derivePassword(password, encoded?.[1] ?? '0'.repeat(64), appKey)
  return Boolean(encoded && same(derived, encoded[2]))
}

export const accounts = new Hono<Api>()
for (const path of ['/auth/*', '/profile', '/profile/*', '/view/*', '/visits']) {
  accounts.use(path, async (c, next) => {
    c.header('Cache-Control', 'no-store')
    await next()
  })
}
accounts.use('/auth/login', bodyLimit({ maxSize: 8192, onError: c => c.json(failure('body_too_large', 'Solicitação muito grande.'), 413) }))

accounts.get('/auth/session', async c => {
  c.set('sessionStage', 'read_session')
  const session = await readSession(c)
  c.set('sessionStage', 'read_account')
  const user = await activeAccount(c, session)
  if (session && (session.user_id === null || user)) {
    return c.json({ data: { user: user ? profile(user, c.req.url) : null, csrf_token: session.csrf_token } })
  }
  c.set('sessionStage', 'rate_limit_session')
  if (!await allowedAttempt(c, 'session:' + (c.req.header('CF-Connecting-IP') ?? 'local'), 150)) {
    return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const value = token()
  const csrf = token()
  c.set('sessionStage', 'create_session')
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
  if (!await verifyPassword(password, stored, appKey) || !user) {
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

/** As escritas de conta exigem sessão ativa, origem permitida e CSRF. */
const authorizeProfileWrite: Handler<Api> = async (c, next) => {
  if (!c.req.header('Origin')) return c.json(failure('origin_required', 'Origem da solicitação ausente.'), 403)
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) return c.json(csrfFailure, 403)
  const user = await activeAccount(c, session)
  if (!user) return c.json(loginRequired, 401)
  c.set('profileSession', session)
  c.set('profileAccount', user)
  await next()
}

const profileFields = [
  ['NOME', 'name', 150], ['APELIDO', 'display_name', 50], ['CIDADE', 'city', 150],
  ['ESTADO', 'state_code', 2], ['CLUBE', 'club', 50], ['ASSOCIACAO', 'association', 50],
  ['TIPO_CLUBE', 'club_type', 50], ['BIOGRAFIA', 'biography', 10000], ['DATA_NASC', 'birth_date', 10],
] as const

accounts.put('/profile', authorizeProfileWrite, bodyLimit({ maxSize: 70_000 }), async c => {
  const user = c.get('profileAccount')
  const body: unknown = await c.req.json().catch(() => null)
  if (!body || typeof body !== 'object' || Array.isArray(body)) return c.json(failure('invalid_profile', 'Dados do perfil inválidos.'), 422)
  const values: (string | null)[] = []
  const assignments: string[] = []
  for (const [field, column, max] of profileFields) {
    if (!Object.hasOwn(body, field)) continue
    const raw = (body as Record<string, unknown>)[field] ?? ''
    if (typeof raw !== 'string' || [...raw].length > max) return c.json(failure('invalid_profile', 'Campo inválido: ' + field), 422)
    const value = raw.trim()
    if (field === 'NOME' && [...value].length < 2) return c.json(failure('invalid_name', 'Informe seu nome.'), 422)
    if (field === 'DATA_NASC' && value) {
      const date = new Date(value + 'T00:00:00Z')
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || !Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) {
        return c.json(failure('invalid_date', 'Data de nascimento inválida.'), 422)
      }
    }
    assignments.push(column + ' = ?')
    values.push(field === 'DATA_NASC' && value === '' ? null : value)
  }
  if (!assignments.length) return c.json({ data: profile(user, c.req.url) })
  const updated = await query(c.env.DB, `UPDATE users SET ${assignments.join(', ')}, updated_at = CURRENT_TIMESTAMP
    WHERE id = ? AND password_hash = ? AND is_active = 1 RETURNING ${accountFields}`, ...values, user.ID, user.SENHA).first<Account>()
  if (!updated) return c.json(csrfFailure, 403)
  notifyProfile(c, user.ID, 'update')
  return c.json({ data: profile(updated, c.req.url) })
})

accounts.post('/profile/photo', authorizeProfileWrite, bodyLimit({ maxSize: 6_700_000 }), async c => {
  const user = c.get('profileAccount')
  if (!await allowedAttempt(c, 'profile-photo:' + user.ID, 20)) {
    return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const body = await c.req.json<{ data?: unknown }>().catch(() => null)
  const bytes = decodeUpload(body?.data)
  const image = bytes && readImage(bytes)
  if (!bytes || !image) return c.json(failure('invalid_upload', 'Envie uma foto PNG, JPEG, GIF ou WebP de até 5 MB e 20 megapixels.'), 422)
  const filename = 'avatar-' + crypto.randomUUID() + '.' + image.extension
  const key = 'users/' + filename
  await c.env.MEDIA.put(key, bytes, { httpMetadata: { contentType: formats[image.extension] } })
  const updated = await query(c.env.DB, `UPDATE users SET avatar_filename = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ? AND avatar_filename IS ? AND password_hash = ? AND is_active = 1 RETURNING id`,
  filename, user.ID, user.FOTO, user.SENHA).first()
  if (!updated) {
    await c.env.MEDIA.delete(key).catch(() => {})
    return c.json(failure('profile_conflict', 'O perfil foi alterado. Recarregue a página e tente novamente.'), 409)
  }
  // Remove apenas fotos geradas por esta rota. Arquivos importados podem ser compartilhados.
  if (user.FOTO && /^avatar-[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\.(?:png|jpg|gif|webp)$/.test(user.FOTO)) {
    const oldKey = 'users/' + user.FOTO
    c.executionCtx.waitUntil(c.env.MEDIA.delete(oldKey).catch(() => {}))
  }
  notifyProfile(c, user.ID, 'update')
  return c.json({ data: { url: mediaUrl(new URL(c.req.url).origin, key) } })
})

accounts.post('/profile/password', authorizeProfileWrite, bodyLimit({ maxSize: 20_000 }), async c => {
  const user = c.get('profileAccount')
  const session = c.get('profileSession')
  const body = await c.req.json<{ password?: unknown; password_confirmation?: unknown; current_password?: unknown }>().catch(() => null)
  const { password, password_confirmation, current_password } = body ?? {}
  if (typeof password !== 'string' || [...password].length < 10 || encoder.encode(password).length > 1024 || password !== password_confirmation) {
    return c.json(failure('invalid_password', 'Use pelo menos 10 caracteres e confirme a nova senha.'), 422)
  }
  const appKey = c.env.APP_KEY ?? ''
  if (encoder.encode(appKey).length < 32) return c.json(failure('auth_not_configured', 'Alteração indisponível.'), 503)
  if (!await allowedAttempt(c, 'profile-password:' + user.ID, 10)) return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  if (typeof current_password !== 'string' || !current_password || encoder.encode(current_password).length > 1024 ||
      !await verifyPassword(current_password, user.SENHA, appKey)) {
    return c.json(failure('invalid_password', 'Senha atual incorreta.'), 422)
  }
  const passwordDigest = await passwordHash(password, appKey)
  const value = token()
  const hash = await digest(value)
  const csrf = token()
  const db = c.env.DB
  const [updated] = await db.batch([
    query(db, `UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND is_active = 1 AND password_hash = ?
        AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ? AND expires_at > unixepoch())
      RETURNING ${accountFields}`, passwordDigest, user.ID, user.SENHA, session.token_hash),
    query(db, `INSERT INTO worker_sessions (token_hash, csrf_token, user_id, password_fingerprint, expires_at)
      SELECT ?, ?, id, ?, ? FROM users WHERE id = ? AND password_hash = ?`,
    hash, csrf, await digest(passwordDigest), session.expires_at, user.ID, passwordDigest),
    query(db, `DELETE FROM worker_sessions WHERE user_id = ? AND token_hash <> ?
      AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ?)`, user.ID, hash, hash),
    ...['worker_password_resets', 'worker_email_changes'].map(table =>
      query(db, `DELETE FROM ${table} WHERE user_id = ?
        AND EXISTS (SELECT 1 FROM worker_sessions WHERE token_hash = ?)`, user.ID, hash)),
  ])
  const updatedUser = updated.results[0] as Account | undefined
  if (!updatedUser) return c.json(csrfFailure, 403)
  const remaining = session.expires_at - now()
  writeCookie(c, value, remaining > 43200 ? remaining : undefined)
  notifyProfile(c, user.ID, 'update')
  return c.json({ data: { user: profile(updatedUser, c.req.url), csrf_token: csrf, message: 'Senha alterada.' } })
})

accounts.post('/profile/email', authorizeProfileWrite, bodyLimit({ maxSize: 8192 }), async c => {
  const user = c.get('profileAccount')
  const session = c.get('profileSession')
  const body = await c.req.json<{ email?: unknown; code?: unknown }>().catch(() => null)
  const email = typeof body?.email === 'string' ? body.email.trim().toLowerCase() : ''
  const code = typeof body?.code === 'string' ? body.code.trim().toLowerCase() : ''
  if (email.length > 50 || !/^[a-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i.test(email)) {
    return c.json(failure('invalid_email', 'Informe um e-mail válido de até 50 caracteres.'), 422)
  }
  if (!await allowedAttempt(c, 'profile-email:' + (code ? 'confirm:' : 'send:') + user.ID, code ? 10 : 5)) {
    return c.json(failure('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const db = c.env.DB
  if (await query(db, 'SELECT id FROM users WHERE email = ? LIMIT 1', email).first()) {
    return c.json(failure('email_exists', 'E-mail já cadastrado.'), 409)
  }
  if (!code) {
    if (!smtpConfigured(c.env)) return c.json(failure('mail_not_configured', 'Envio de e-mail indisponível.'), 503)
    const newCode = token().slice(0, 20)
    const tokenHash = await digest(newCode)
    const pending = await query(db, `INSERT INTO worker_email_changes (user_id, email, token_hash, expires_at)
      SELECT id, ?, ?, unixepoch() + 1800 FROM users WHERE id = ? AND is_active = 1 AND email = ? AND password_hash = ?
      ON CONFLICT(user_id) DO UPDATE SET email = excluded.email, token_hash = excluded.token_hash, expires_at = excluded.expires_at
      RETURNING user_id`, email, tokenHash, user.ID, user.EMAIL, user.SENHA).first()
    if (!pending) return c.json(csrfFailure, 403)
    try {
      await securityMail(c, email, 'Confirme o novo e-mail MDAWiki', 'Use este código para confirmar seu novo endereço em até 30 minutos.', { code: newCode })
    } catch {
      await query(db, 'DELETE FROM worker_email_changes WHERE user_id = ? AND token_hash = ?', user.ID, tokenHash).run()
      return c.json(failure('mail_delivery_failed', 'Não foi possível enviar o código. Tente novamente.'), 503)
    }
    return c.json({ data: { message: 'Código enviado para o novo e-mail.' } })
  }
  const invalidCode = failure('invalid_code', 'Código inválido ou expirado. Solicite outro código.')
  if (!/^[a-f0-9]{20}$/.test(code)) return c.json(invalidCode, 422)
  const tokenHash = await digest(code)
  const [updated] = await db.batch([
    query(db, `UPDATE users SET email = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND is_active = 1 AND email = ? AND password_hash = ?
        AND EXISTS (SELECT 1 FROM worker_email_changes WHERE user_id = users.id AND email = ?
          AND token_hash = ? AND expires_at > unixepoch())
        AND NOT EXISTS (SELECT 1 FROM users other WHERE other.email = ?)
      RETURNING ${accountFields}`, email, user.ID, user.EMAIL, user.SENHA, email, tokenHash, email),
    query(db, `DELETE FROM worker_password_resets WHERE user_id = ? AND EXISTS (
      SELECT 1 FROM users WHERE id = ? AND email = ? AND password_hash = ?)`, user.ID, user.ID, email, user.SENHA),
    query(db, `DELETE FROM worker_email_changes WHERE user_id = ? AND token_hash = ? AND EXISTS (
      SELECT 1 FROM users WHERE id = ? AND email = ? AND password_hash = ?)`, user.ID, tokenHash, user.ID, email, user.SENHA),
  ])
  const updatedUser = updated.results[0] as Account | undefined
  if (!updatedUser) return c.json(invalidCode, 422)
  notifyProfile(c, user.ID, 'update')
  return c.json({ data: { user: profile(updatedUser, c.req.url), csrf_token: session.csrf_token, message: 'E-mail alterado.' } })
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
    query(db, 'DELETE FROM worker_email_changes WHERE expires_at <= ?', timestamp),
    query(db, 'DELETE FROM worker_rate_limits WHERE window < ?', Math.floor(timestamp / 3600)),
    query(db, 'DELETE FROM page_visits WHERE visited_at < ?', timestamp - 31 * 86400),
  ])
}
