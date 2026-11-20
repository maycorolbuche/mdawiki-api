CREATE TABLE IF NOT EXISTS migration_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id CHAR(32) CHARACTER SET ascii NOT NULL,
    source_table VARCHAR(64) NOT NULL,
    source_key JSON NOT NULL,
    source_field VARCHAR(64) NULL,
    error_message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX migration_errors_run_idx (run_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
