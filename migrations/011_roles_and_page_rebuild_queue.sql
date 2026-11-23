ALTER TABLE users
    ADD COLUMN role ENUM('reader', 'user', 'admin') NOT NULL DEFAULT 'user';

UPDATE users SET role = CASE access_level_id
    WHEN 0 THEN 'reader'
    WHEN 5 THEN 'admin'
    ELSE 'user'
END;

CREATE TABLE IF NOT EXISTS page_rebuild_queue (
    page_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO page_rebuild_queue (page_id)
SELECT id FROM pages WHERE needs_rebuild = 1
    AND kind <> 'image' AND legacy_identifier NOT LIKE 'page@%'
ON DUPLICATE KEY UPDATE page_id = page_rebuild_queue.page_id;
