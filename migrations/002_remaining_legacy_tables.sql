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
    image_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    is_template BOOLEAN,
    FOREIGN KEY (page_id) REFERENCES pages (id),
    FOREIGN KEY (image_id) REFERENCES images (id)
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
