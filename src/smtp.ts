import { connect } from 'cloudflare:sockets'
import type { Env } from './types'

const encoder = new TextEncoder()
const address = /^[a-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i
const base64 = (value: string) => {
  const bytes = encoder.encode(value)
  let binary = ''
  for (let i = 0; i < bytes.length; i += 8192) binary += String.fromCharCode(...bytes.subarray(i, i + 8192))
  return btoa(binary)
}
const wrap = (value: string) => base64(value).match(/.{1,76}/g) ?? []

export function smtpConfigured(env: Env) {
  const port = Number(env.SMTP_PORT ?? '587')
  return /^[a-z0-9.-]+$/i.test(env.SMTP_HOST ?? '') && address.test(env.SMTP_FROM ?? '') &&
    Number.isInteger(port) && port > 0 && port < 65536 && port !== 25 &&
    ['ssl', 'tls'].includes(env.SMTP_SECURE ?? 'tls') && (!env.SMTP_USERNAME || Boolean(env.SMTP_PASSWORD))
}

/** Envia por TLS implícito ou STARTTLS, sem transmitir credenciais em texto claro. */
export async function sendSmtp(env: Env, to: string | string[], subject: string, text: string, options: { html?: string; originalTo?: string } = {}) {
  const recipients = typeof to === 'string' ? [to] : to
  const visible = options.originalTo ? [options.originalTo] : recipients
  if (!smtpConfigured(env) || !recipients.length || [...recipients, ...visible].some(value => !address.test(value))) throw new Error('SMTP não configurado.')
  const implicit = env.SMTP_SECURE === 'ssl'
  let socket = connect({ hostname: env.SMTP_HOST!, port: Number(env.SMTP_PORT ?? '587') },
    { secureTransport: implicit ? 'on' : 'starttls', allowHalfOpen: false })
  void socket.closed.catch(() => {})
  let reader = socket.readable.getReader()
  let writer = socket.writable.getWriter()
  let buffer = ''
  let accepted = false
  const deadline = setTimeout(() => { void socket.close().catch(() => {}) }, 15_000)

  async function command(line: string | null, allowed: number[]) {
    if (line !== null) await writer.write(encoder.encode(line + '\r\n'))
    const lines: string[] = []
    for (;;) {
      const end = buffer.indexOf('\n')
      if (end < 0) {
        const chunk = await reader.read()
        if (chunk.done) throw new Error('Conexão SMTP encerrada.')
        buffer += new TextDecoder().decode(chunk.value)
        if (buffer.length > 16_384) throw new Error('Resposta SMTP muito grande.')
        continue
      }
      const reply = buffer.slice(0, end).trimEnd()
      buffer = buffer.slice(end + 1)
      if (!/^\d{3}[ -]/.test(reply) || lines.length >= 100) throw new Error('Resposta SMTP inválida.')
      lines.push(reply.slice(4))
      if (reply[3] === '-') continue
      if (!allowed.includes(Number(reply.slice(0, 3)))) throw new Error('Falha SMTP (' + reply.slice(0, 3) + ').')
      return lines
    }
  }

  try {
    await command(null, [220])
    let capabilities = await command('EHLO ' + env.SMTP_FROM!.split('@')[1], [250])
    if (!implicit) {
      if (!capabilities.some(line => /^STARTTLS\b/i.test(line))) throw new Error('STARTTLS indisponível.')
      await command('STARTTLS', [220])
      reader.releaseLock()
      writer.releaseLock()
      socket = socket.startTls()
      void socket.closed.catch(() => {})
      reader = socket.readable.getReader()
      writer = socket.writable.getWriter()
      buffer = ''
      capabilities = await command('EHLO ' + env.SMTP_FROM!.split('@')[1], [250])
    }
    if (env.SMTP_USERNAME) {
      const schemes = capabilities.filter(line => /^AUTH[ =]/i.test(line)).join(' ')
      if (/\bPLAIN\b/i.test(schemes)) {
        await command('AUTH PLAIN ' + base64('\0' + env.SMTP_USERNAME + '\0' + env.SMTP_PASSWORD), [235])
      } else if (/\bLOGIN\b/i.test(schemes)) {
        await command('AUTH LOGIN', [334])
        await command(base64(env.SMTP_USERNAME), [334])
        await command(base64(env.SMTP_PASSWORD!), [235])
      } else throw new Error('Autenticação SMTP incompatível.')
    }
    await command('MAIL FROM:<' + env.SMTP_FROM + '>', [250])
    for (const recipient of recipients) await command('RCPT TO:<' + recipient + '>', [250, 251])
    await command('DATA', [354])
    const boundary = crypto.randomUUID()
    const part = (format: 'plain' | 'html', content: string) => [
      'Content-Type: text/' + format + '; charset=utf-8',
      'Content-Transfer-Encoding: base64', '', ...wrap(content),
    ]
    const body = options.html === undefined ? part('plain', text) : [
      'Content-Type: multipart/alternative; boundary="' + boundary + '"', '',
      '--' + boundary, ...part('plain', text),
      '--' + boundary, ...part('html', options.html), '--' + boundary + '--',
    ]
    const message = [
      'From: MDAWiki <' + env.SMTP_FROM + '>',
      'To: ' + visible.map(value => '<' + value + '>').join(',\r\n '),
      'Subject: ' + (/^[\x20-\x7e]{1,70}$/.test(subject) ? subject : '=?UTF-8?B?' + base64(subject) + '?='),
      'Date: ' + new Date().toUTCString(),
      'Message-ID: <' + crypto.randomUUID() + '@' + env.SMTP_FROM!.split('@')[1] + '>',
      'MIME-Version: 1.0', ...body, '.',
    ].join('\r\n')
    await command(message, [250])
    accepted = true
  } finally {
    clearTimeout(deadline)
    reader.releaseLock()
    writer.releaseLock()
    await socket.close().catch(() => {})
    if (!accepted) console.warn(JSON.stringify({ event: 'smtp_delivery_failed' }))
  }
}
