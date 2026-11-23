ALTER TABLE users
    DROP COLUMN receives_notifications,
    DROP COLUMN access_level_id;

ALTER TABLE pages
    DROP COLUMN access_level_id,
    DROP COLUMN needs_rebuild,
    DROP COLUMN is_html;
