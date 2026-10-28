import { eq } from 'drizzle-orm';
import type { Database } from '../db';
import { media } from '../db/schema';

export class Media {
  constructor(private db: Database) {}
  find(id: number) { return this.db.select().from(media).where(eq(media.id, id)).get(); }
}
