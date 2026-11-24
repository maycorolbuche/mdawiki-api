import { Hono } from 'hono'
import { bodyLimit } from 'hono/body-limit'
import { allowedAttempt, passwordHash, readSession, same } from './accounts'
import { failureReason, type RegistrationStage } from './diagnostics'
import { sendSmtp, smtpConfigured } from './smtp'
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

async function deliverCode(env: Env, email: string, code: string, subject: string) {
  const activation = new URL(env.FRONTEND_URL.replace(/\/$/, '') + '/p/ativar-conta')
  activation.searchParams.set('code', code)
  activation.searchParams.set('email', email)
  await sendSmtp(env, email, subject, 'Use este código para ativar sua conta MDAWiki: ' + code + '\n\n' + activation.href)
}

export const registration = new Hono<{ Bindings: Env; Variables: { registrationStage: RegistrationStage } }>()
registration.on('POST', ['/auth/register', '/auth/activate', '/auth/resend'], bodyLimit({ maxSize: 8192 }), async c => {
  c.header('Cache-Control', 'no-store')
  if (!c.req.header('Origin')) return c.json(error('origin_required', 'Origem da solicitação ausente.'), 403)
  c.set('registrationStage', 'session')
  const session = await readSession(c)
  if (!session || !same(c.req.header('X-CSRF-Token') ?? '', session.csrf_token)) {
    return c.json(error('csrf_failed', 'Sessão expirada. Atualize a página e tente novamente.'), 403)
  }
  const body = await c.req.json<{ name?: unknown; email?: unknown; password?: unknown; password_confirmation?: unknown; code?: unknown }>().catch(() => null)
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
        await deliverCode(c.env, email, activation.code, 'Código de ativação MDAWiki').catch(() => {})
        break
      }
    }
    return c.json(generic)
  }

  const name = typeof body?.name === 'string' ? body.name.trim() : ''
  const password = body?.password
  if ([...name].length < 2 || [...name].length > 150) return c.json(error('invalid_name', 'Informe seu nome.'), 422)
  if (typeof password !== 'string' || [...password].length < 6 || encoder.encode(password).length > 1024) {
    return c.json(error('invalid_password', 'Use uma senha com pelo menos 6 caracteres.'), 422)
  }
  if (password !== body?.password_confirmation) {
    return c.json(error('password_mismatch', 'A confirmação de senha está diferente.'), 422)
  }

  // O sal legado depende do ID. A inserção só aceita o ID candidato e um e-mail ainda livre.
  for (let attempt = 0; attempt < 3; attempt++) {
    c.set('registrationStage', 'find_account')
    if (await query(db, 'SELECT id FROM users WHERE email = ? LIMIT 1', email).first()) {
      return c.json(error('email_exists', 'Este e-mail já possui cadastro.'), 409)
    }
    c.set('registrationStage', 'allocate_user_id')
    const id = await query(db, `SELECT MAX(COALESCE(MAX(id), 0),
      COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'users'), 0)) + 1 AS id FROM users`).first<number>('id')
    if (!Number.isSafeInteger(id) || id === null) throw new Error('ID inválido.')
    const publicId = crypto.randomUUID().replaceAll('-', '')
    const activation = await newCode(key, email)
    c.set('registrationStage', 'hash_password')
    const passwordDigest = await passwordHash(password, id, key)
    c.set('registrationStage', 'save_account')
    const results = await db.batch([
      query(db, `INSERT INTO users (id, email, name, password_hash, display_name, public_id, is_active, created_at)
        SELECT ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = ?)
        ON CONFLICT DO NOTHING RETURNING id`, id, email, name, passwordDigest, email.split('@')[0].slice(0, 50), publicId, email),
      query(db, `INSERT INTO user_activations (user_id, token_hash, expires_at)
        SELECT id, ?, datetime('now', '+1 day') FROM users WHERE id = ? AND public_id = ?`, activation.hash, id, publicId),
    ])
    if (!results[0].results.length) continue
    try {
      await deliverCode(c.env, email, activation.code, 'Ative sua conta MDAWiki')
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
  }
  return c.json(error('registration_busy', 'Tente cadastrar novamente em alguns instantes.'), 503)
})
