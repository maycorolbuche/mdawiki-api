import { eq, desc } from 'drizzle-orm';
import type { Database } from '../db';
import { pageRevisions } from '../db/schema';

export class PageRevision {
  constructor(private db: Database) {}
  forPage(pageId: number) {
    return this.db.select().from(pageRevisions)
      .where(eq(pageRevisions.pageId, pageId))
      .orderBy(desc(pageRevisions.version)).all();
  }
}
