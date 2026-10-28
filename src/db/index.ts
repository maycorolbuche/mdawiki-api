import { drizzle } from 'drizzle-orm/d1';
import * as schema from './schema';

export const database = (binding: D1Database) => drizzle(binding, { schema });
export type Database = ReturnType<typeof database>;

/**
 * D1 batch is atomic. Drizzle builds typed statements; D1 executes them together.
 * This adapter is needed for dynamic-length batches (categories/media).
 */
export async function atomic(binding: D1Database, statements: Array<{ toSQL(): { sql: string; params: unknown[] } }>) {
  if (!statements.length) return;
  await binding.batch(statements.map(statement => {
    const { sql, params } = statement.toSQL();
    return binding.prepare(sql).bind(...params);
  }));
}
