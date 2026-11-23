CREATE TABLE IF NOT EXISTS system_error_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    component VARCHAR(50) NOT NULL,
    operation VARCHAR(50) NOT NULL,
    error_code VARCHAR(80) NOT NULL,
    message VARCHAR(500) NOT NULL,
    context TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX system_error_logs_component_date (component, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
