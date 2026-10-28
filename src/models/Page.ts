import { and, eq, ne, sql } from 'drizzle-orm';
import { database, type Database } from '../db';
import { categories, legacyPageMappings, media, pageCategories, pageMedia, pageRevisions, pages } from '../db/schema';

export type PageRecord = typeof pages.$inferSelect;
export type PageInput = typeof pages.$inferInsert;
export type CategoryRecord = typeof categories.$inferSelect;

export class Page {
  constructor(public readonly db: Database) {}

  async findBySlug(slug: string) {
    return this.db.select().from(pages).where(eq(pages.slug, slug)).get();
  }

  async findById(id: number) {
    return this.db.select().from(pages).where(eq(pages.id, id)).get();
  }

  async list(limit: number, offset: number, type: string | null) {
    return this.db.select().from(pages)
      .where(type ? and(ne(pages.type, 'redirect'), eq(pages.type, type)) : ne(pages.type, 'redirect'))
      .orderBy(pages.id).limit(limit).offset(offset).all();
  }

  async categories(pageId: number) {
    return this.db.select({
      id: categories.id, name: categories.name, slug: categories.slug,
      description: categories.description, parent_id: categories.parentId,
      created_at: categories.createdAt, updated_at: categories.updatedAt,
    }).from(categories).innerJoin(pageCategories, eq(pageCategories.categoryId, categories.id))
      .where(eq(pageCategories.pageId, pageId)).orderBy(pageCategories.position).all();
  }

  async latestVersion(pageId: number) {
    const row = await this.db.select({ version: sql<number>`coalesce(max(${pageRevisions.version}), 0)` })
      .from(pageRevisions).where(eq(pageRevisions.pageId, pageId)).get();
    return Number(row?.version ?? 0);
  }

  async redirectTarget(id: number) {
    const target = await this.findById(id);
    return target && target.type !== 'redirect' ? target : undefined;
  }

  async mediaExists(id: number) {
    return !!(await this.db.select({ id: media.id }).from(media).where(eq(media.id, id)).get());
  }

  async categoryById(id: number) {
    return this.db.select().from(categories).where(eq(categories.id, id)).get();
  }

  insert(data: PageInput) { return this.db.insert(pages).values(data); }
  update(id: number, data: Partial<PageInput>) { return this.db.update(pages).set(data).where(eq(pages.id, id)); }
  addRevision(pageId: number, version: number, snapshot: Record<string, unknown>, changeSummary: string | null) {
    return this.db.insert(pageRevisions).values({ pageId, version, snapshot, changeSummary });
  }
  createRevisionForSlug(slug: string, snapshot: Record<string, unknown>, changeSummary: string | null) {
    return this.db.insert(pageRevisions).values({
      pageId: sql`(select id from pages where slug = ${slug})` as unknown as number,
      version: 1, snapshot, changeSummary,
    });
  }
  clearCategories(slug: string) {
    return this.db.delete(pageCategories).where(eq(pageCategories.pageId, sql`(select id from pages where slug = ${slug})`));
  }
  addCategory(name: string, slug: string) {
    return this.db.insert(categories).values({ name, slug }).onConflictDoNothing({ target: categories.slug });
  }
  attachCategory(slug: string, categorySlug: string, position: number) {
    return this.db.insert(pageCategories).values({
      pageId: sql`(select id from pages where slug = ${slug})` as unknown as number,
      categoryId: sql`(select id from categories where slug = ${categorySlug})` as unknown as number,
      position,
    });
  }
  clearMedia(slug: string) {
    return this.db.delete(pageMedia).where(eq(pageMedia.pageId, sql`(select id from pages where slug = ${slug})`));
  }
  attachMedia(slug: string, id: number) {
    return this.db.insert(pageMedia).values({
      pageId: sql`(select id from pages where slug = ${slug})` as unknown as number,
      mediaId: id,
    });
  }
  removeRedirectsTo(pageId: number) {
    return this.db.delete(pages).where(and(eq(pages.type, 'redirect'), sql`json_extract(${pages.metadata}, '$.redirect') = ${pageId}`));
  }
  deleteById(id: number) { return this.db.delete(pages).where(eq(pages.id, id)); }
}
