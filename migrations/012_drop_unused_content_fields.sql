DROP TABLE IF EXISTS comments, settings, access_levels;

ALTER TABLE users
    DROP COLUMN needs_related_page_rebuild,
    DROP COLUMN uuid,
    DROP COLUMN last_created_page_at,
    DROP COLUMN created_page_count,
    DROP COLUMN last_edited_page_at,
    DROP COLUMN edited_page_count,
    DROP COLUMN last_deleted_page_at,
    DROP COLUMN deleted_page_count,
    DROP COLUMN visit_count;

ALTER TABLE pages DROP COLUMN is_sponsored;
