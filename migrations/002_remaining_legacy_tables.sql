CREATE TABLE IF NOT EXISTS comments (
    `id` INT NOT NULL,
    page_id BIGINT UNSIGNED NULL,
    FOREIGN KEY (page_id) REFERENCES pages (id),
    `author_email` VARCHAR(150),
    `body` LONGTEXT,
    `parent_id` INT,
    `created_at` DATETIME,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `setting_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `setting_value` LONGTEXT,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_profile_settings (
    `profile_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `setting_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `setting_value` VARCHAR(50),
    PRIMARY KEY (`profile_key`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_template_recipients (
    `event_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `to_address` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `cc` VARCHAR(100),
    `bcc` VARCHAR(100),
    `subject_override` VARCHAR(50),
    `body_override` LONGTEXT,
    PRIMARY KEY (`event_key`, `to_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_templates (
    `event_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `description` LONGTEXT,
    `subject` VARCHAR(50),
    `body` LONGTEXT,
    `profile_key` VARCHAR(50),
    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_levels (
    `id` INT NOT NULL,
    `description` VARCHAR(50),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wiki_markup_rules (
    `rule_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(50),
    `source_pattern` VARCHAR(50),
    `html_pattern` VARCHAR(250),
    `handler` VARCHAR(50),
    `position` INT,
    `scope` VARCHAR(50),
    PRIMARY KEY (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    `id` INT NOT NULL,
    `email` VARCHAR(150),
    `name` VARCHAR(150),
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` BOOLEAN,
    `display_name` VARCHAR(50),
    `legacy_avatar_path` LONGTEXT,
    `legacy_thumbnail_path` LONGTEXT,
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
    `legacy_access_level` INT,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    avatar_file_id BIGINT UNSIGNED NULL,
    thumbnail_file_id BIGINT UNSIGNED NULL,
    FOREIGN KEY (avatar_file_id) REFERENCES files (id),
    FOREIGN KEY (thumbnail_file_id) REFERENCES files (id),
    INDEX users_email_idx (email),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    UNIQUE (page_id, category_id),
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (category_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    image_page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    is_template BOOLEAN,
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (image_page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_includes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    target_page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    section_anchor VARCHAR(250),
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (target_page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    target_page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    fragment VARCHAR(250),
    link_text VARCHAR(250),
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (target_page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_external_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    href VARCHAR(250) NOT NULL,
    link_text VARCHAR(250),
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    target_page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (target_page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_headings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    title VARCHAR(250),
    heading_key VARCHAR(250),
    anchor VARCHAR(250),
    heading_level INT,
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    template_key VARCHAR(250),
    template_source VARCHAR(250),
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_videos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    url VARCHAR(250),
    error_message VARCHAR(250),
    FOREIGN KEY (page_id) REFERENCES pages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
