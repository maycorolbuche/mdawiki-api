import type { Context } from 'hono'
import { failureReason } from './diagnostics'
import { mediaUrl } from './pages'
import { sendSmtp } from './smtp'
import { query, type Env } from './types'

type MailContext = Pick<Context<{ Bindings: Env }>, 'env' | 'req' | 'executionCtx'>
type Action = 'new' | 'update' | 'delete'
type Category = 'user' | 'page' | 'img'
type Detail = string | number | null | undefined

export function mailRecipients(value = '') {
  const unique = new Map<string, string>()
  for (const entry of value.split(/[;,]/)) {
    const address = entry.trim()
    if (/^[a-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i.test(address)) {
      unique.set(address.toLowerCase(), address)
    }
  }
  return [...unique.values()]
}

const escape = (value: string) => value.replace(/[&<>"']/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[character]!)

function layout(env: Env, subject: string, content: string) {
  const site = escape(env.FRONTEND_URL.replace(/\/$/, ''))
  return `<!doctype html><html lang="pt-BR"><meta charset="utf-8">
    <body style="background:#eff3f7;color:#23354b;font:16px Arial,sans-serif">
    <table role="presentation" width="100%" style="max-width:620px;margin:20px auto;background:white;border-radius:12px">
    <tr><td style="padding:24px;border-bottom:3px solid #164a77"><a href="${site}"><img src="${site}/assets/logo_mdawiki.svg" width="160" alt="MDAWiki"></a></td></tr>
    <tr><td style="padding:24px;line-height:1.55;overflow-wrap:anywhere"><h1 style="font-size:24px">${escape(subject)}</h1>${content}</td></tr>
    <tr><td style="padding:16px 24px;background:#f4f7fa">Mensagem automática · MDAWiki</td></tr>
    </table></body></html>`
}

const button = (url: string, label: string) =>
  `<p><a style="display:inline-block;background:#164a77;color:white;padding:10px 18px;border-radius:6px" href="${escape(url)}">${escape(label)}</a></p>`

/** O registro não inclui destinatários, assunto, corpo, credenciais ou tokens. */
export async function logMailFailure(env: Env, operation: string, error: unknown) {
  const reason = error instanceof Error ? failureReason(error) : 'unexpected_error'
  console.error(JSON.stringify({ event: 'mail_failed', operation, reason }))
  await query(env.DB, `INSERT INTO system_error_logs (component, operation, error_code, message, context)
    VALUES ('mail', ?, ?, 'Falha no envio de e-mail.', '{}')`, operation, reason).run().catch(() => {})
}

function optional(c: MailContext, operation: string, task: () => Promise<void>) {
  c.executionCtx.waitUntil(task().catch(error => logMailFailure(c.env, operation, error)))
}

type OptionalMessage = {
  recipients: string[]; subject: string; text: string; html: string; originalTo?: string
}

async function deliverOptional(env: Env, message: OptionalMessage) {
  await sendSmtp(env, message.recipients, message.subject, message.text, {
    html: message.html, originalTo: message.originalTo,
  })
}

/** Enfileira apenas notificações e cópias sem segredos. O e-mail principal é síncrono. */
async function deliverOrQueue(env: Env, operation: string, message: OptionalMessage) {
  try {
    await deliverOptional(env, message)
  } catch (error) {
    await logMailFailure(env, operation, error)
    await query(env.DB, `INSERT INTO worker_mail_outbox (operation, payload, next_attempt_at)
      VALUES (?, ?, unixepoch() + 300)`, operation, JSON.stringify(message)).run()
  }
}

/** A atualização do prazo funciona como uma concessão temporária contra envios concorrentes. */
export async function retryOptionalMail(env: Env) {
  const rows = await query(env.DB, `UPDATE worker_mail_outbox
    SET next_attempt_at = unixepoch() + 600
    WHERE id IN (SELECT id FROM worker_mail_outbox WHERE next_attempt_at <= unixepoch()
      ORDER BY next_attempt_at, id LIMIT 3)
    RETURNING id, operation, payload, attempts`)
    .all<{ id: number; operation: string; payload: string; attempts: number }>()
  for (const row of rows.results) {
    try {
      await deliverOptional(env, JSON.parse(row.payload) as OptionalMessage)
    } catch (error) {
      await logMailFailure(env, row.operation, error)
      if (row.attempts < 4) {
        await query(env.DB, `UPDATE worker_mail_outbox SET attempts = attempts + 1,
          next_attempt_at = unixepoch() + ? WHERE id = ?`, 300 * 2 ** row.attempts, row.id).run()
        continue
      }
    }
    await query(env.DB, 'DELETE FROM worker_mail_outbox WHERE id = ?', row.id).run()
  }
}

/** A cópia usa apenas o envelope SMTP e omite códigos e links de acesso, como no PHP. */
export async function securityMail(c: MailContext, recipient: string, subject: string,
  message: string, options: { code?: string; url?: string } = {}) {
  const { code, url } = options
  const plain = [message, code, url].filter(Boolean).join('\n\n')
  const content = `<p>${escape(message)}</p>` + (code ? `<p><strong>${escape(code)}</strong></p>` : '')
    + (url ? button(url, 'Continuar no MDAWiki') : '')
  try {
    await sendSmtp(c.env, recipient, subject, plain, { html: layout(c.env, subject, content) })
  } catch (error) {
    optional(c, 'security_log', () => logMailFailure(c.env, 'security', error))
    throw error
  }
  const copies = mailRecipients(c.env.MAIL_USER_COPY_TO).filter(value => value.toLowerCase() !== recipient.toLowerCase())
  if (!copies.length) return
  const preview = [message, code && '[Código ocultado]', url && '[Link de acesso ocultado]'].filter(Boolean).join('\n\n')
  optional(c, 'user_copy', () => deliverOrQueue(c.env, 'user_copy', {
    recipients: copies, subject, text: preview, originalTo: recipient, html: layout(c.env, subject, `<p>${escape(preview).replaceAll('\n', '<br>')}</p>`),
  }))
}

async function sendEvent(env: Env, recipients: string[], category: Category, action: Action,
  title: string, actor: string, fields: Record<string, Detail>, path?: string, image?: string | null) {
  const subject = `[${category}][${action}] ${title.replace(/[\x00-\x1f\x7f]+/g, ' ').trim()}`
  const label = { page: 'Abrir página', img: 'Abrir imagem', user: 'Abrir perfil' }[category]
  const url = path ? new URL(path, env.FRONTEND_URL).href : undefined
  const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'medium', timeZone: 'America/Sao_Paulo' }).format(new Date())
  const entries = Object.entries({ Responsável: actor, 'Data e hora (Brasília)': date, ...fields })
    .filter(([, value]) => value != null && value !== '')
  const body = entries.map(([key, value]) => `<p><strong>${escape(key)}</strong><br>${escape(String(value)).replaceAll('\n', '<br>')}</p>`).join('')
  const preview = image && /^https?:\/\//i.test(image) ? `<img src="${escape(image)}" alt="Prévia" width="240" style="max-width:100%;height:auto">` : ''
  const plain = entries.map(([key, value]) => `${key}\n${value}`).join('\n\n') + (url ? `\n\n${label}\n${url}` : '')
  await deliverOrQueue(env, category + '_' + action, { recipients, subject, text: plain,
    html: layout(env, subject, body + preview + (url ? button(url, label) : '')) })
}

export function notifyEvent(c: MailContext, category: 'page' | 'img', action: Action,
  title: string, actor: string, fields: Record<string, Detail>, path: string, image?: string | null) {
  const recipients = mailRecipients(c.env.MAIL_ADMIN_TO)
  if (recipients.length) optional(c, category + '_' + action, () => sendEvent(c.env, recipients, category, action, title, actor, fields, path, image))
}

export function notifyProfile(c: MailContext, id: number, action: 'new' | 'update') {
  const recipients = mailRecipients(c.env.MAIL_ADMIN_TO)
  if (!recipients.length) return
  optional(c, 'user_' + action, async () => {
    const account = await query(c.env.DB, `SELECT id AS ID, name AS Nome, display_name AS "Nome de exibição",
      email AS "E-mail", birth_date AS "Data de nascimento", city AS Cidade, state_code AS Estado,
      club AS Clube, association AS Associação, club_type AS "Tipo de clube", biography AS Biografia,
      role AS "Nível de acesso", CASE is_active WHEN 1 THEN 'Sim' ELSE 'Não' END AS Ativo,
      created_at AS Cadastro, updated_at AS "Última alteração", avatar_filename, public_id
      FROM users WHERE id = ?`, id).first<Record<string, Detail> & { avatar_filename: string | null; public_id: string }>()
    if (!account) return
    const { avatar_filename, public_id, ...details } = account
    const name = String(details.Nome || details['E-mail'] || id)
    const image = avatar_filename ? mediaUrl(new URL(c.req.url).origin, 'users/' + avatar_filename) : null
    const path = '/u/' + encodeURIComponent(public_id)
    await sendEvent(c.env, recipients, 'user', action, name, name, details, path, image)
  })
}
