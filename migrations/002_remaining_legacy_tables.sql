CREATE TABLE IF NOT EXISTS session_events (
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_occurrence BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(150),
    `user_agent` LONGTEXT,
    `ip_address` VARCHAR(50),
    `logged_in_at` DATETIME,
    `logged_out_at` DATETIME,
    `session_key` VARCHAR(50),
    PRIMARY KEY (`source_hash`, `source_occurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    `id` INT NOT NULL,
    `page_slug` VARCHAR(250),
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

CREATE TABLE IF NOT EXISTS scheduled_task_runs (
    `id` INT NOT NULL,
    `task` VARCHAR(50),
    `started_at` DATETIME,
    `finished_at` DATETIME,
    `parameters` LONGTEXT,
    `result` LONGTEXT,
    PRIMARY KEY (`id`)
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

CREATE TABLE IF NOT EXISTS legacy_mail_deliveries (
    `id` INT NOT NULL,
    `legacy_uid` VARCHAR(50),
    `event_key` VARCHAR(50),
    `profile_key` VARCHAR(50),
    `created_at` DATETIME,
    `subject` LONGTEXT,
    `body` LONGTEXT,
    `to_address` VARCHAR(100),
    `cc` VARCHAR(100),
    `bcc` VARCHAR(100),
    `legacy_status` CHAR(1),
    `last_attempt_at` DATETIME,
    `attempt_count` INT,
    `last_result` LONGTEXT,
    PRIMARY KEY (`id`)
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

CREATE TABLE IF NOT EXISTS legacy_user_presence (
    `session_key` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
    `connected_at` DATETIME,
    `last_seen_at` DATETIME,
    `ip_address` VARCHAR(50),
    `user_agent` LONGTEXT,
    `email` VARCHAR(150),
    `session_timeout` INT,
    PRIMARY KEY (`session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_visit_events (
    `id` INT NOT NULL,
    `legacy_page_identifier` VARCHAR(50),
    `page_slug` VARCHAR(250),
    `visited_at` DATETIME,
    `legacy_visit_number` INT,
    `user_agent` LONGTEXT,
    `ip_address` VARCHAR(50),
    `request_parameters` LONGTEXT,
    `user_reference` VARCHAR(250),
    `description` VARCHAR(250),
    `request_address` VARCHAR(150),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_daily_visits (
    `id` INT NOT NULL,
    `visit_date` DATE,
    `legacy_page_identifier` VARCHAR(250),
    `page_slug` VARCHAR(250),
    `visit_count` INT,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_rebuild_jobs (
    `id` INT NOT NULL,
    `legacy_page_identifier` VARCHAR(250),
    `page_slug` VARCHAR(250),
    `queued_at` DATETIME,
    `processed_at` DATETIME,
    `legacy_updated` CHAR(1),
    `position` INT,
    `requester_email` VARCHAR(200),
    `is_started` BOOLEAN,
    `is_finished` BOOLEAN,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_rebuild_runs (
    `started_at` DATETIME NOT NULL,
    `updated_count` INT,
    `started_count` INT,
    `finished_count` INT,
    PRIMARY KEY (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_term_statistics (
    `term` VARCHAR(300) COLLATE utf8mb4_bin NOT NULL,
    `search_count` INT,
    `last_searched_at` DATETIME,
    PRIMARY KEY (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_categories (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `category_name` VARCHAR(250),
    `category_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `category_slug` VARCHAR(250),
    `position` INT,
    PRIMARY KEY (`legacy_page_identifier`, `category_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_media (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `image_identifier` VARCHAR(250),
    `image_title` VARCHAR(250),
    `image_slug` VARCHAR(250),
    `legacy_file_url` VARCHAR(250),
    `legacy_thumbnail_url` VARCHAR(250),
    `legacy_cover_url` VARCHAR(250),
    `legacy_template` CHAR(1),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_includes (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `target_title` VARCHAR(250),
    `target_identifier` VARCHAR(250),
    `target_slug` VARCHAR(250),
    `section_anchor` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_links (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `target_title` VARCHAR(250),
    `target_identifier` VARCHAR(250),
    `href` VARCHAR(250),
    `fragment` VARCHAR(250),
    `link_text` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_external_links (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `href` VARCHAR(250),
    `link_text` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_redirects (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `target_title` VARCHAR(250),
    `target_identifier` VARCHAR(250),
    `target_slug` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_headings (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `title` VARCHAR(250),
    `heading_key` VARCHAR(250),
    `anchor` VARCHAR(250),
    `heading_level` INT,
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_page_cache (
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_occurrence BIGINT UNSIGNED NOT NULL,
    `position` INT,
    `title` VARCHAR(250),
    `page_slug` VARCHAR(250),
    `legacy_page_identifier` VARCHAR(250),
    `image_reference` LONGTEXT,
    `summary` LONGTEXT,
    `item_count` INT,
    `cache_key` VARCHAR(50),
    `updated_at` DATETIME,
    `is_marked_for_deletion` BOOLEAN,
    PRIMARY KEY (`source_hash`, `source_occurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_templates (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `template_key` VARCHAR(250),
    `template_source` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_videos (
    `legacy_page_identifier` VARCHAR(250) COLLATE utf8mb4_bin NOT NULL,
    `page_slug` VARCHAR(250),
    `legacy_position` INT NOT NULL,
    `url` VARCHAR(250),
    `error_message` VARCHAR(250),
    PRIMARY KEY (`legacy_position`, `legacy_page_identifier`)
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
