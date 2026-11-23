ALTER TABLE users DROP COLUMN legacy_access_level;

ALTER TABLE pages DROP COLUMN legacy_checksum;

ALTER TABLE page_revisions
    DROP INDEX page_revisions_legacy_unique,
    DROP COLUMN legacy_revision_number,
    DROP COLUMN legacy_checksum;

ALTER TABLE image_revisions
    ADD INDEX image_revisions_image_idx (image_id);

ALTER TABLE image_revisions
    DROP INDEX image_id,
    DROP COLUMN legacy_revision_number;

DROP TABLE IF EXISTS migration_errors;
