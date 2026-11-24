export type Env = {
  DB: D1Database
  MEDIA: R2Bucket
  FRONTEND_URL: string
  ALLOWED_ORIGINS: string
  APP_KEY?: string
  SMTP_HOST?: string
  SMTP_PORT?: string
  SMTP_SECURE?: string
  SMTP_USERNAME?: string
  SMTP_PASSWORD?: string
  SMTP_FROM?: string
  MAIL_USER_COPY_TO?: string
  MAIL_ADMIN_TO?: string
}

export type SqlValue = string | number | null

/** Prepara apenas valores. As expressões SQL são definidas no código. */
export function query(db: D1Database, sql: string, ...values: SqlValue[]) {
  return db.prepare(sql).bind(...values)
}

export const validSlug = (slug: string) => slug.length > 0 && [...slug].length <= 250 && !/[\/\\\x00-\x1f\x7f]/.test(slug)
