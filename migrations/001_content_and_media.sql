CREATE TABLE IF NOT EXISTS files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    storage_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_identifier VARCHAR(250) COLLATE utf8mb4_bin NOT NULL UNIQUE,
    identifier VARCHAR(250) COLLATE utf8mb4_bin NOT NULL UNIQUE,
    slug VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    title VARCHAR(250),
    kind VARCHAR(16) NOT NULL,
    source LONGTEXT,
    rendered_html LONGTEXT,
    plain_text LONGTEXT,
    is_searchable BOOLEAN,
    access_level_id INT,
    needs_rebuild BOOLEAN,
    view_count BIGINT,
    is_html BOOLEAN,
    is_sponsored BOOLEAN,
    created_by_legacy VARCHAR(200),
    updated_by_legacy VARCHAR(200),
    created_at DATETIME,
    updated_at DATETIME,
    rebuilt_at DATETIME,
    legacy_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    INDEX pages_slug_idx (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    legacy_revision_number INT NOT NULL,
    revision_number BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(250) NOT NULL,
    title VARCHAR(250),
    source LONGTEXT,
    rendered_html LONGTEXT,
    is_searchable BOOLEAN,
    access_level_id INT,
    editor_email VARCHAR(200),
    legacy_user_id INT,
    is_expired BOOLEAN,
    created_at DATETIME,
    legacy_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY page_revisions_legacy_unique (page_id, legacy_revision_number),
    INDEX page_revisions_order_idx (page_id, revision_number),
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL UNIQUE,
    file_id BIGINT UNSIGNED,
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_revisions (
    page_revision_id BIGINT UNSIGNED PRIMARY KEY,
    image_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED,
    FOREIGN KEY (page_revision_id) REFERENCES page_revisions (id),
    FOREIGN KEY (image_id) REFERENCES images (id),
    FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
