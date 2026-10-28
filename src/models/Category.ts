import { eq } from 'drizzle-orm';
import type { Database } from '../db';
import { categories } from '../db/schema';

export class Category {
  constructor(private db: Database) {}
  find(id: number) { return this.db.select().from(categories).where(eq(categories.id, id)).get(); }
  findBySlug(slug: string) { return this.db.select().from(categories).where(eq(categories.slug, slug)).get(); }
}
