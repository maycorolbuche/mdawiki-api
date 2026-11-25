export type SessionStage = 'read_session' | 'read_account' | 'rate_limit_session' | 'create_session'

export type RegistrationStage = 'session' | 'rate_limit' | 'activate' | 'resend' |
  'find_account' | 'allocate_user_id' | 'hash_password' | 'save_account' | 'send_activation'

/** Registra categorias estáveis, sem copiar mensagens que possam conter dados da requisição. */
export function failureReason(failure: Error): string {
  if (failure instanceof DOMException) return 'web_crypto_' + failure.name
  const cause = failure.cause instanceof Error ? failure.cause.message : ''
  const message = failure.message + '\n' + cause
  if (/no such table/i.test(message)) return 'database_missing_table'
  if (/no such column|has no column named/i.test(message)) return 'database_missing_column'
  if (/constraint failed/i.test(message)) return 'database_constraint'
  if (/D1_ERROR|SQLITE_ERROR/i.test(message)) {
    if (/overload|too many requests/i.test(message)) return 'database_overloaded'
    if (/timeout|timed out|deadline exceeded/i.test(message)) return 'database_timeout'
    if (/quota|daily limit|limit exceeded/i.test(message)) return 'database_limit'
    const sqlite = /\bSQLITE_(BUSY|LOCKED|READONLY|FULL|IOERR|CORRUPT|MISMATCH|TOOBIG|CANTOPEN)\b/i.exec(message)
    return sqlite ? 'database_sqlite_' + sqlite[1].toLowerCase() : 'database_error'
  }
  const smtpStatus = /^Falha SMTP \(([45][0-9]{2})\)\.$/.exec(failure.message)
  if (smtpStatus) return 'smtp_' + smtpStatus[1]
  return 'unexpected_error'
}
