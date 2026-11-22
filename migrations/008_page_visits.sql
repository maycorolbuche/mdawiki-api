CREATE TABLE IF NOT EXISTS page_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    visited_at BIGINT UNSIGNED NOT NULL,
    INDEX page_visits_window (visited_at, page_id),
    FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB;
