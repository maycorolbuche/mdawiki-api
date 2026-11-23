CREATE TABLE IF NOT EXISTS files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    `id` INT NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(150),
    `name` VARCHAR(150),
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` BOOLEAN,
    `display_name` VARCHAR(50),
    avatar_filename VARCHAR(100),
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    `birth_date` DATE,
    `city` VARCHAR(150),
    `state_code` CHAR(2),
    `club` VARCHAR(50),
    `association` VARCHAR(50),
    `club_type` VARCHAR(50),
    `biography` LONGTEXT,
    `needs_related_page_rebuild` BOOLEAN,
    `receives_notifications` BOOLEAN,
    `has_logged_in` BOOLEAN,
    `access_level_id` INT,
    `uuid` VARCHAR(100),
    `last_created_page_at` DATETIME,
    `created_page_count` INT,
    `last_edited_page_at` DATETIME,
    `edited_page_count` INT,
    `last_deleted_page_at` DATETIME,
    `deleted_page_count` INT,
    `last_seen_at` DATETIME,
    `visit_count` INT,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    INDEX users_email_idx (email),
    PRIMARY KEY (`id`)
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
    created_by INT,
    updated_by INT,
    created_at DATETIME,
    updated_at DATETIME,
    rebuilt_at DATETIME,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX pages_slug_idx (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    revision_number BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(250) NOT NULL,
    title VARCHAR(250),
    source LONGTEXT,
    rendered_html LONGTEXT,
    is_searchable BOOLEAN,
    access_level_id INT,
    created_by INT,
    is_expired BOOLEAN,
    created_at DATETIME,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX page_revisions_order_idx (page_id, revision_number),
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(250) COLLATE utf8mb4_bin NOT NULL UNIQUE,
    slug VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    title VARCHAR(250) NOT NULL,
    meta JSON NOT NULL,
    created_by INT,
    created_at DATETIME,
    updated_by INT,
    updated_at DATETIME,
    file_id BIGINT UNSIGNED,
    legacy_identifier VARCHAR(250) COLLATE utf8mb4_bin UNIQUE,
    access_level_id INT NOT NULL DEFAULT 1,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED,
    created_by INT,
    access_level_id INT NOT NULL DEFAULT 1,
    created_at DATETIME,
    INDEX image_revisions_image_idx (image_id),
    FOREIGN KEY (image_id) REFERENCES images (id),
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
