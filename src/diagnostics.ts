export type RegistrationStage = 'session' | 'rate_limit' | 'activate' | 'resend' |
  'find_account' | 'allocate_user_id' | 'hash_password' | 'save_account' | 'send_activation'

/** Registra categorias estáveis, sem copiar mensagens que possam conter dados da requisição. */
export function failureReason(failure: Error): string {
  if (failure instanceof DOMException) return 'web_crypto_' + failure.name
  const message = failure.message
  if (/no such table/i.test(message)) return 'database_missing_table'
  if (/no such column|has no column named/i.test(message)) return 'database_missing_column'
  if (/constraint failed/i.test(message)) return 'database_constraint'
  if (/D1_ERROR|SQLITE_ERROR/i.test(message)) return 'database_error'
  const smtpStatus = /^Falha SMTP \(([45][0-9]{2})\)\.$/.exec(message)
  if (smtpStatus) return 'smtp_' + smtpStatus[1]
  return 'unexpected_error'
}
