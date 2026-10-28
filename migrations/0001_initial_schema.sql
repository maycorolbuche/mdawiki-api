-- Cloudflare D1 / SQLite. Datas em UTC no formato YYYY-MM-DD HH:MM:SS.
-- updated_at deve ser preenchido pela aplicação em cada alteração.
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 150),
  display_name TEXT,
  email TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','blocked')),
  avatar_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
  bio TEXT,
  email_verified_at TEXT,
  last_login_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  original_name TEXT NOT NULL,
  storage_key TEXT NOT NULL UNIQUE,
  mime_type TEXT NOT NULL,
  size_bytes INTEGER NOT NULL CHECK (size_bytes >= 0),
  width INTEGER CHECK (width > 0),
  height INTEGER CHECK (height > 0),
  alt_text TEXT,
  caption TEXT,
  credit TEXT,
  license TEXT,
  uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK (length(trim(type)) BETWEEN 1 AND 50),
  title TEXT NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 250),
  slug TEXT NOT NULL UNIQUE CHECK (length(slug) BETWEEN 1 AND 250),
  content TEXT,
  content_text TEXT,
  summary TEXT,
  metadata TEXT NOT NULL DEFAULT '{}' CHECK (CASE WHEN json_valid(metadata) THEN json_type(metadata) = 'object' ELSE 0 END),
  cover_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  is_searchable INTEGER NOT NULL DEFAULT 1 CHECK (is_searchable IN (0,1)),
  is_sponsored INTEGER NOT NULL DEFAULT 0 CHECK (is_sponsored IN (0,1)),
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  published_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX pages_type_status ON pages(type, status);
CREATE INDEX pages_status_searchable ON pages(status, is_searchable);
CREATE INDEX pages_updated_at ON pages(updated_at);
CREATE INDEX pages_created_by ON pages(created_by);
CREATE INDEX pages_updated_by ON pages(updated_by);
CREATE INDEX pages_cover_media ON pages(cover_media_id);

CREATE TABLE categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 250),
  slug TEXT NOT NULL UNIQUE CHECK (length(slug) BETWEEN 1 AND 250),
  description TEXT,
  parent_id INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE INDEX categories_parent ON categories(parent_id);

CREATE TABLE page_categories (
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 0 CHECK (position >= 0),
  PRIMARY KEY (page_id, category_id)
);
CREATE INDEX page_categories_category ON page_categories(category_id, page_id);

CREATE TABLE page_media (
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE RESTRICT,
  PRIMARY KEY (page_id, media_id)
);
CREATE INDEX page_media_media ON page_media(media_id, page_id);
CREATE INDEX media_uploaded_by ON media(uploaded_by);
CREATE INDEX users_avatar_media ON users(avatar_media_id);

CREATE TABLE page_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  version INTEGER NOT NULL CHECK (version > 0),
  snapshot TEXT NOT NULL CHECK (CASE WHEN json_valid(snapshot) THEN json_type(snapshot) = 'object' ELSE 0 END),
  change_summary TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (page_id, version)
);
CREATE INDEX page_revisions_created_by ON page_revisions(created_by);

CREATE TABLE page_daily_stats (
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  date TEXT NOT NULL CHECK (length(date) = 10 AND date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
  views INTEGER NOT NULL DEFAULT 0 CHECK (views >= 0),
  PRIMARY KEY (page_id, date)
);
CREATE INDEX page_daily_stats_date ON page_daily_stats(date, page_id);

CREATE TABLE legacy_page_mappings (
  legacy_ident TEXT PRIMARY KEY NOT NULL,
  legacy_url TEXT NOT NULL,
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE RESTRICT,
  migrated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX legacy_page_mappings_page ON legacy_page_mappings(page_id);
