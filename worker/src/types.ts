export type Env = {
  DB: D1Database
  MEDIA: R2Bucket
  FRONTEND_URL: string
  ALLOWED_ORIGINS: string
}

export type SqlValue = string | number | null

/** Prepara apenas valores. As expressões SQL são definidas no código. */
export function query(db: D1Database, sql: string, ...values: SqlValue[]) {
  return db.prepare(sql).bind(...values)
}
