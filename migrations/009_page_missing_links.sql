CREATE TABLE IF NOT EXISTS page_missing_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    href VARCHAR(251) COLLATE utf8mb4_bin NOT NULL,
    position INT NOT NULL,
    fragment VARCHAR(250),
    link_text VARCHAR(250),
    INDEX page_missing_links_href (href),
    FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
