import { integer, primaryKey, sqliteTable, text, uniqueIndex } from 'drizzle-orm/sqlite-core';
import { sql } from 'drizzle-orm';

export const pages = sqliteTable('pages', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  type: text('type').notNull(),
  title: text('title').notNull(),
  slug: text('slug').notNull().unique(),
  content: text('content'),
  contentText: text('content_text'),
  summary: text('summary'),
  metadata: text('metadata', { mode: 'json' }).$type<Record<string, unknown>>().notNull().default({}),
  coverMediaId: integer('cover_media_id'),
  status: text('status', { enum: ['draft', 'published', 'archived'] }).notNull().default('draft'),
  isSearchable: integer('is_searchable', { mode: 'boolean' }).notNull().default(true),
  isSponsored: integer('is_sponsored', { mode: 'boolean' }).notNull().default(false),
  createdBy: integer('created_by'),
  updatedBy: integer('updated_by'),
  publishedAt: text('published_at'),
  createdAt: text('created_at').notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text('updated_at').notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const categories = sqliteTable('categories', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  name: text('name').notNull(),
  slug: text('slug').notNull().unique(),
  description: text('description'),
  parentId: integer('parent_id'),
  createdAt: text('created_at').notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text('updated_at').notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const media = sqliteTable('media', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  originalName: text('original_name').notNull(),
  storageKey: text('storage_key').notNull().unique(),
  mimeType: text('mime_type').notNull(),
  sizeBytes: integer('size_bytes').notNull(),
  width: integer('width'),
  height: integer('height'),
  altText: text('alt_text'),
  caption: text('caption'),
  credit: text('credit'),
  license: text('license'),
  uploadedBy: integer('uploaded_by'),
  createdAt: text('created_at').notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text('updated_at').notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const pageCategories = sqliteTable('page_categories', {
  pageId: integer('page_id').notNull(),
  categoryId: integer('category_id').notNull(),
  position: integer('position').notNull().default(0),
}, t => [primaryKey({ columns: [t.pageId, t.categoryId] })]);

export const pageMedia = sqliteTable('page_media', {
  pageId: integer('page_id').notNull(),
  mediaId: integer('media_id').notNull(),
}, t => [primaryKey({ columns: [t.pageId, t.mediaId] })]);

export const pageRevisions = sqliteTable('page_revisions', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  pageId: integer('page_id').notNull(),
  version: integer('version').notNull(),
  snapshot: text('snapshot', { mode: 'json' }).$type<Record<string, unknown>>().notNull(),
  changeSummary: text('change_summary'),
  createdBy: integer('created_by'),
  createdAt: text('created_at').notNull().default(sql`CURRENT_TIMESTAMP`),
}, t => [uniqueIndex('page_revisions_page_version').on(t.pageId, t.version)]);

export const users = sqliteTable('users', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  name: text('name').notNull(),
  displayName: text('display_name'),
  email: text('email').notNull().unique(),
  passwordHash: text('password_hash').notNull(),
  status: text('status', { enum: ['pending', 'active', 'blocked'] }).notNull().default('pending'),
  avatarMediaId: integer('avatar_media_id'),
  bio: text('bio'),
  emailVerifiedAt: text('email_verified_at'),
  lastLoginAt: text('last_login_at'),
  createdAt: text('created_at').notNull().default(sql`CURRENT_TIMESTAMP`),
  updatedAt: text('updated_at').notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const pageDailyStats = sqliteTable('page_daily_stats', {
  pageId: integer('page_id').notNull(),
  date: text('date').notNull(),
  views: integer('views').notNull().default(0),
}, t => [primaryKey({ columns: [t.pageId, t.date] })]);

export const legacyPageMappings = sqliteTable('legacy_page_mappings', {
  legacyIdent: text('legacy_ident').primaryKey(),
  legacyUrl: text('legacy_url').notNull(),
  pageId: integer('page_id').notNull(),
  migratedAt: text('migrated_at').notNull().default(sql`CURRENT_TIMESTAMP`),
});
