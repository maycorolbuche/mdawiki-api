import { Hono } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { allowedAttempt, passwordHash, readSession, same } from './accounts'
import { failureReason, type RegistrationStage } from './diagnostics'
import { smtpConfigured } from './smtp'
import { notifyProfile, securityMail } from './wiki-mail'
import type { Context } from 'hono'
import { query, type Env } from './types'

const error = (code: string, message: string) => ({ error: { code, message } })
const invalidCode = error('invalid_code', 'Código inválido ou expirado. Solicite outro código.')
const mailUnavailable = error('mail_not_configured', 'O envio de e-mail ainda não foi configurado na API.')
const generic = { data: { message: 'Se houver uma conta correspondente, você receberá um e-mail com as instruções.' } }
const encoder = new TextEncoder()
const emailAddress = /^[a-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i
const hex = (bytes: ArrayBuffer) => Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('')
type Account = { id: number; email: string; is_active: number }

async function activationHash(key: string, email: string, code: string) {
  const hmac = await crypto.subtle.importKey('raw', encoder.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  return hex(await crypto.subtle.sign('HMAC', hmac, encoder.encode('activation:' + email + ':' + code)))
}

async function newCode(key: string, email: string) {
  const digits = new Uint32Array(1)
  do { crypto.getRandomValues(digits) } while (digits[0] >= 4_294_000_000)
  const code = String(digits[0] % 1_000_000).padStart(6, '0')
  return { code, hash: await activationHash(key, email, code) }
}

async function deliverCode(c: Context<{ Bindings: Env }>, email: string, code: string, subject: string) {
  const activation = new URL(c.env.FRONTEND_URL.replace(/\/$/, '') + '/p/ativar-conta')
  activation.searchParams.set('code', code)
  activation.searchParams.set('email', email)
  await securityMail(c, email, subject, 'Use este código para ativar sua conta MDAWiki.', { code, url: activation.href })
}

function validatePassword(password: unknown, confirmation: unknown, minimum = 6) {
  if (typeof password !== 'string' || [...password].length < minimum || encoder.encode(password).length > 1024) {
    return error('invalid_password', `Use uma senha com pelo menos ${minimum} caracteres.`)
  }
  return password === confirmation ? null : error('password_mismatch', 'A confirmação de senha está diferente.')
}

export const registration = new Hono<{ Bindings: Env; Variables: { registrationStage: RegistrationStage } }>()
registration.on('POST', ['/auth/register', '/auth/activate', '/auth/resend', '/auth/forgot', '/auth/reset'], bodyLimit({ maxSize: 8192 }), async c => {
  c.header('Cache-Control', 'no-store')
  if (!c.req.header('Origin')) return c.json(error('origin_required', 'Origem da solicitação ausente.'), 403)
  c.set('registrationStage', 'session')
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) {
    return c.json(error('csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.'), 403)
  }
  const body = await c.req.json<{ name?: unknown; email?: unknown; password?: unknown; password_confirmation?: unknown; code?: unknown; token?: unknown }>().catch(() => null)
  const email = typeof body?.email === 'string' ? body.email.trim().toLowerCase() : ''
  if (!emailAddress.test(email) || email.length > 150) {
    return c.json(error('invalid_email', 'Informe um e-mail válido.'), 422)
  }
  const key = c.env.APP_KEY ?? ''
  if (encoder.encode(key).length < 32) return c.json(error('auth_not_configured', 'Cadastro indisponível.'), 503)
  const action = c.req.path.split('/').pop()
  const ip = c.req.header('CF-Connecting-IP') ?? 'local'
  c.set('registrationStage', 'rate_limit')
  if (!await allowedAttempt(c, 'registration:' + action + ':ip:' + ip, 20) ||
      !await allowedAttempt(c, 'registration:' + action + ':email:' + email, 10)) {
    return c.json(error('rate_limited', 'Muitas tentativas. Tente novamente mais tarde.'), 429)
  }
  const db = c.env.DB
  if (action === 'forgot' || action === 'reset') {
    const timestamp = Math.floor(Date.now() / 1000)
    if (action === 'forgot') {
      if (!smtpConfigured(c.env)) return c.json(mailUnavailable, 503)
      const rawToken = hex(crypto.getRandomValues(new Uint8Array(32)).buffer)
      const tokenHash = hex(await crypto.subtle.digest('SHA-256', encoder.encode(rawToken)))
      const saved = await query(db, `INSERT INTO worker_password_resets (user_id, token_hash, expires_at)
        SELECT id, ?, ? FROM users WHERE email = ? AND is_active = 1
          AND (SELECT COUNT(*) FROM users WHERE email = ?) = 1
        ON CONFLICT(user_id) DO UPDATE SET token_hash = excluded.token_hash, expires_at = excluded.expires_at
        RETURNING user_id`, tokenHash, timestamp + 1800, email, email).first()
      if (saved) {
        const link = new URL('/p/redefinir-senha', c.env.FRONTEND_URL)
        link.searchParams.set('email', email)
        link.searchParams.set('token', rawToken)
        await securityMail(c, email, 'Redefina sua senha MDAWiki', 'Use este link em até 30 minutos.', { url: link.href }).catch(() => {})
      }
      return c.json(generic)
    }
    const rawToken = body?.token
    if (typeof rawToken !== 'string' || !/^[a-f0-9]{64}$/.test(rawToken)) return c.json(invalidCode, 422)
    const password = body?.password
    const validation = validatePassword(password, body?.password_confirmation, 10)
    if (validation) return c.json(validation, 422)
    const tokenHash = hex(await crypto.subtle.digest('SHA-256', encoder.encode(rawToken)))
    const account = await query(db, `SELECT r.user_id FROM worker_password_resets r JOIN users u ON u.id = r.user_id
      WHERE r.token_hash = ? AND r.expires_at > ? AND u.email = ? AND u.is_active = 1`,
    tokenHash, timestamp, email).first<{ user_id: number }>()
    if (!account) return c.json(invalidCode, 422)
    c.set('registrationStage', 'hash_password')
    const hash = await passwordHash(password as string, key)
    const [updated] = await db.batch([
      query(db, `UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND email = ?
        AND is_active = 1 AND EXISTS (SELECT 1 FROM worker_password_resets WHERE user_id = users.id
          AND token_hash = ? AND expires_at > unixepoch())`, hash, account.user_id, email, tokenHash),
      query(db, `DELETE FROM worker_password_resets WHERE user_id = ? AND token_hash = ?
        AND EXISTS (SELECT 1 FROM users WHERE id = ? AND password_hash = ?)`,
      account.user_id, tokenHash, account.user_id, hash),
      query(db, `DELETE FROM worker_email_changes WHERE user_id = ?
        AND EXISTS (SELECT 1 FROM users WHERE id = ? AND password_hash = ?)`,
      account.user_id, account.user_id, hash),
    ])
    if (updated.meta.changes) notifyProfile(c, account.user_id, 'update')
    return updated.meta.changes ? c.json({ data: { message: 'Senha redefinida. Você já pode entrar.' } }) : c.json(invalidCode, 422)
  }
  if (action === 'activate') {
    c.set('registrationStage', 'activate')
    const code = body?.code
    if (typeof code !== 'string' || !/^\d{6}$/.test(code)) return c.json(invalidCode, 422)
    const hash = await activationHash(key, email, code)
    const legacyHash = hex(await crypto.subtle.digest('SHA-256', encoder.encode(code)))
    const results = await db.batch([
      query(db, `UPDATE users SET is_active = 1, updated_at = CURRENT_TIMESTAMP
        WHERE email = ? AND is_active = 0 AND password_hash <> '' AND id IN (
          SELECT user_id FROM user_activations WHERE token_hash IN (?, ?) AND expires_at > CURRENT_TIMESTAMP
        ) RETURNING id`, email, hash, legacyHash),
      query(db, `DELETE FROM user_activations WHERE token_hash IN (?, ?) AND user_id IN (
        SELECT id FROM users WHERE email = ? AND is_active = 1
      )`, hash, legacyHash, email),
    ])
    for (const row of results[0].results) notifyProfile(c, Number(row.id), 'update')
    return results[0].results.length === 1 ? c.json({ data: { message: 'Conta ativada. Você já pode entrar.' } }) : c.json(invalidCode, 422)
  }

  if (!smtpConfigured(c.env)) return c.json(mailUnavailable, 503)
  if (action === 'resend') {
    c.set('registrationStage', 'resend')
    const rows = await query(db, 'SELECT id, email, is_active FROM users WHERE email = ? LIMIT 2', email).all<Account>()
    const account = rows.results.length === 1 ? rows.results[0] : null
    if (!account || account.is_active !== 0) return c.json(generic)
    for (let attempt = 0; attempt < 3; attempt++) {
      const activation = await newCode(key, email)
      const legacyHash = hex(await crypto.subtle.digest('SHA-256', encoder.encode(activation.code)))
      const inserted = await query(db, `INSERT INTO user_activations(user_id, token_hash, expires_at)
        SELECT id, ?, datetime('now', '+1 day') FROM users WHERE id = ? AND is_active = 0 AND password_hash <> ''
        ON CONFLICT(user_id) DO UPDATE SET token_hash = excluded.token_hash, expires_at = excluded.expires_at
          WHERE user_activations.token_hash NOT IN (excluded.token_hash, ?)
        RETURNING user_id`, activation.hash, account.id, legacyHash).first()
      if (inserted) {
        await deliverCode(c, email, activation.code, 'Código de ativação MDAWiki').catch(() => {})
        break
      }
    }
    return c.json(generic)
  }

  const name = typeof body?.name === 'string' ? body.name.trim() : ''
  const password = body?.password
  if ([...name].length < 2 || [...name].length > 150) return c.json(error('invalid_name', 'Informe seu nome.'), 422)
  const validation = validatePassword(password, body?.password_confirmation)
  if (validation) return c.json(validation, 422)

  const publicId = crypto.randomUUID().replaceAll('-', '')
  const activation = await newCode(key, email)
  c.set('registrationStage', 'hash_password')
  const passwordDigest = await passwordHash(password as string, key)
  c.set('registrationStage', 'save_account')
  const results = await db.batch([
    query(db, `INSERT INTO users (email, name, password_hash, display_name, public_id, is_active, created_at)
      SELECT ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = ?)
      ON CONFLICT DO NOTHING RETURNING id`, email, name, passwordDigest, email.split('@')[0].slice(0, 50), publicId, email),
    query(db, `INSERT INTO user_activations (user_id, token_hash, expires_at)
      SELECT id, ?, datetime('now', '+1 day') FROM users WHERE public_id = ?`, activation.hash, publicId),
  ])
  if (!results[0].results.length) return c.json(error('email_exists', 'Este e-mail já possui cadastro.'), 409)
  notifyProfile(c, Number(results[0].results[0].id), 'new')
  try {
    await deliverCode(c, email, activation.code, 'Ative sua conta MDAWiki')
  } catch (failure) {
    const request_id = crypto.randomUUID()
    console.error(JSON.stringify({
      request_id, event: 'request_failed', stage: 'send_activation',
      reason: failure instanceof Error ? failureReason(failure) : 'unexpected_error',
    }))
    return c.json({ error: {
      code: 'activation_delivery_failed',
      message: 'Cadastro criado. Solicite um novo código na tela de ativação.',
      request_id,
    } }, 503)
  }
  return c.json({ data: { message: 'Cadastro criado. Confira seu e-mail para ativar a conta.' } })
})
