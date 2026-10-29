ALTER TABLE pages ADD COLUMN deleted_by INTEGER;
ALTER TABLE pages ADD COLUMN deleted_at TEXT;
CREATE INDEX pages_deleted_at_idx ON pages(deleted_at);
