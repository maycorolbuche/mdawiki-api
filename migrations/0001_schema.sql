-- Schema D1 da API, baseado em migrations/001_database.sql (MySQL).

CREATE TABLE IF NOT EXISTS `files` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `sha256` TEXT NOT NULL,
  `storage_key` TEXT NOT NULL,
  `mime_type` TEXT NOT NULL,
  `size_bytes` INTEGER NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (`storage_key`)
);

CREATE TABLE IF NOT EXISTS `system_error_logs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `component` TEXT NOT NULL,
  `operation` TEXT NOT NULL,
  `error_code` TEXT NOT NULL,
  `message` TEXT NOT NULL,
  `context` TEXT NOT NULL,
  `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS `system_error_logs_system_error_logs_component_date` ON `system_error_logs` (`component`,`created_at`);

CREATE TABLE IF NOT EXISTS `users` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `email` TEXT DEFAULT NULL COLLATE NOCASE,
  `name` TEXT DEFAULT NULL,
  `password_hash` TEXT NOT NULL,
  `is_active` INTEGER DEFAULT NULL,
  `display_name` TEXT DEFAULT NULL,
  `avatar_filename` TEXT DEFAULT NULL,
  `role` TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('reader', 'user', 'admin')),
  `public_id` TEXT NOT NULL,
  `birth_date` TEXT DEFAULT NULL,
  `city` TEXT DEFAULT NULL,
  `state_code` TEXT DEFAULT NULL,
  `club` TEXT DEFAULT NULL,
  `association` TEXT DEFAULT NULL,
  `club_type` TEXT DEFAULT NULL,
  `biography` TEXT,
  `has_logged_in` INTEGER DEFAULT NULL,
  `last_seen_at` TEXT DEFAULT NULL,
  `created_at` TEXT DEFAULT NULL,
  `updated_at` TEXT DEFAULT NULL,
  UNIQUE (`public_id`)
);

CREATE INDEX IF NOT EXISTS `users_users_email_idx` ON `users` (`email`);

CREATE TABLE IF NOT EXISTS `wiki_markup_rules` (
  `rule_key` TEXT NOT NULL,
  `name` TEXT DEFAULT NULL,
  `source_pattern` TEXT DEFAULT NULL,
  `html_pattern` TEXT DEFAULT NULL,
  `handler` TEXT DEFAULT NULL,
  `position` INTEGER DEFAULT NULL,
  `scope` TEXT DEFAULT NULL,
  PRIMARY KEY (`rule_key`)
);

CREATE TABLE IF NOT EXISTS `images` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `identifier` TEXT NOT NULL,
  `slug` TEXT NOT NULL,
  `title` TEXT NOT NULL,
  `meta` TEXT NOT NULL CHECK (json_valid(meta)),
  `file_id` INTEGER DEFAULT NULL,
  `legacy_identifier` TEXT DEFAULT NULL,
  `access_level_id` INTEGER NOT NULL DEFAULT 1,
  `created_by` INTEGER DEFAULT NULL,
  `created_at` TEXT DEFAULT NULL,
  `updated_by` INTEGER DEFAULT NULL,
  `updated_at` TEXT DEFAULT NULL,
  UNIQUE (`identifier`),
  UNIQUE (`legacy_identifier`),
  CONSTRAINT `images_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `images_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `images_ibfk_3` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`)
);

CREATE INDEX IF NOT EXISTS `images_updated_by` ON `images` (`updated_by`);

CREATE INDEX IF NOT EXISTS `images_created_by` ON `images` (`created_by`);

CREATE INDEX IF NOT EXISTS `images_file_id` ON `images` (`file_id`);

CREATE TABLE IF NOT EXISTS `pages` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `legacy_identifier` TEXT NOT NULL,
  `identifier` TEXT NOT NULL,
  `slug` TEXT NOT NULL,
  `title` TEXT DEFAULT NULL,
  `kind` TEXT NOT NULL,
  `source` TEXT,
  `rendered_html` TEXT,
  `plain_text` TEXT,
  `is_searchable` INTEGER DEFAULT NULL,
  `view_count` INTEGER DEFAULT NULL,
  `rebuilt_at` TEXT DEFAULT NULL,
  `created_by` INTEGER DEFAULT NULL,
  `updated_by` INTEGER DEFAULT NULL,
  `created_at` TEXT DEFAULT NULL,
  `updated_at` TEXT DEFAULT NULL,
  UNIQUE (`legacy_identifier`),
  UNIQUE (`identifier`),
  CONSTRAINT `pages_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pages_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS `pages_created_by` ON `pages` (`created_by`);

CREATE INDEX IF NOT EXISTS `pages_updated_by` ON `pages` (`updated_by`);

CREATE INDEX IF NOT EXISTS `pages_pages_slug_idx` ON `pages` (`slug`);

CREATE TABLE IF NOT EXISTS `user_activations` (
  `user_id` INTEGER NOT NULL,
  `token_hash` TEXT NOT NULL,
  `expires_at` TEXT NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE (`token_hash`),
  CONSTRAINT `user_activations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `image_revisions` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `image_id` INTEGER NOT NULL,
  `file_id` INTEGER DEFAULT NULL,
  `access_level_id` INTEGER NOT NULL DEFAULT 1,
  `created_by` INTEGER DEFAULT NULL,
  `created_at` TEXT DEFAULT NULL,
  CONSTRAINT `image_revisions_ibfk_1` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`),
  CONSTRAINT `image_revisions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `image_revisions_ibfk_3` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`)
);

CREATE INDEX IF NOT EXISTS `image_revisions_created_by` ON `image_revisions` (`created_by`);

CREATE INDEX IF NOT EXISTS `image_revisions_file_id` ON `image_revisions` (`file_id`);

CREATE INDEX IF NOT EXISTS `image_revisions_image_revisions_image_idx` ON `image_revisions` (`image_id`);

CREATE TABLE IF NOT EXISTS `page_categories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `category_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  UNIQUE (`page_id`,`category_id`),
  CONSTRAINT `page_categories_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`),
  CONSTRAINT `page_categories_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_categories_category_id` ON `page_categories` (`category_id`);

CREATE TABLE IF NOT EXISTS `page_external_links` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `href` TEXT NOT NULL,
  `link_text` TEXT DEFAULT NULL,
  CONSTRAINT `page_external_links_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_external_links_page_id` ON `page_external_links` (`page_id`);

CREATE TABLE IF NOT EXISTS `page_headings` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `title` TEXT DEFAULT NULL,
  `heading_key` TEXT DEFAULT NULL,
  `anchor` TEXT DEFAULT NULL,
  `heading_level` INTEGER DEFAULT NULL,
  CONSTRAINT `page_headings_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_headings_page_id` ON `page_headings` (`page_id`);

CREATE TABLE IF NOT EXISTS `page_includes` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `target_page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `section_anchor` TEXT DEFAULT NULL,
  CONSTRAINT `page_includes_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`),
  CONSTRAINT `page_includes_ibfk_2` FOREIGN KEY (`target_page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_includes_page_id` ON `page_includes` (`page_id`);

CREATE INDEX IF NOT EXISTS `page_includes_target_page_id` ON `page_includes` (`target_page_id`);

CREATE TABLE IF NOT EXISTS `page_links` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `target_page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `fragment` TEXT DEFAULT NULL,
  `link_text` TEXT DEFAULT NULL,
  CONSTRAINT `page_links_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`),
  CONSTRAINT `page_links_ibfk_2` FOREIGN KEY (`target_page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_links_page_id` ON `page_links` (`page_id`);

CREATE INDEX IF NOT EXISTS `page_links_target_page_id` ON `page_links` (`target_page_id`);

CREATE TABLE IF NOT EXISTS `page_media` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `image_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `is_template` INTEGER DEFAULT NULL,
  CONSTRAINT `page_media_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`),
  CONSTRAINT `page_media_ibfk_2` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_media_page_id` ON `page_media` (`page_id`);

CREATE INDEX IF NOT EXISTS `page_media_image_id` ON `page_media` (`image_id`);

CREATE TABLE IF NOT EXISTS `page_missing_links` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `href` TEXT NOT NULL,
  `position` INTEGER NOT NULL,
  `fragment` TEXT DEFAULT NULL,
  `link_text` TEXT DEFAULT NULL,
  CONSTRAINT `page_missing_links_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS `page_missing_links_page_missing_links_href` ON `page_missing_links` (`href`);

CREATE INDEX IF NOT EXISTS `page_missing_links_page_id` ON `page_missing_links` (`page_id`);

CREATE TABLE IF NOT EXISTS `page_rebuild_queue` (
  `page_id` INTEGER NOT NULL,
  `queued_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`page_id`),
  CONSTRAINT `page_rebuild_queue_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `page_redirects` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `target_page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  CONSTRAINT `page_redirects_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`),
  CONSTRAINT `page_redirects_ibfk_2` FOREIGN KEY (`target_page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_redirects_page_id` ON `page_redirects` (`page_id`);

CREATE INDEX IF NOT EXISTS `page_redirects_target_page_id` ON `page_redirects` (`target_page_id`);

CREATE TABLE IF NOT EXISTS `page_revisions` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `revision_number` INTEGER NOT NULL,
  `slug` TEXT NOT NULL,
  `title` TEXT DEFAULT NULL,
  `source` TEXT,
  `rendered_html` TEXT,
  `is_searchable` INTEGER DEFAULT NULL,
  `access_level_id` INTEGER DEFAULT NULL,
  `is_expired` INTEGER DEFAULT NULL,
  `created_by` INTEGER DEFAULT NULL,
  `created_at` TEXT DEFAULT NULL,
  CONSTRAINT `page_revisions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `page_revisions_ibfk_2` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_revisions_created_by` ON `page_revisions` (`created_by`);

CREATE INDEX IF NOT EXISTS `page_revisions_page_revisions_order_idx` ON `page_revisions` (`page_id`,`revision_number`);

CREATE TABLE IF NOT EXISTS `page_templates` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `template_key` TEXT DEFAULT NULL,
  `template_source` TEXT DEFAULT NULL,
  CONSTRAINT `page_templates_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_templates_page_id` ON `page_templates` (`page_id`);

CREATE TABLE IF NOT EXISTS `page_videos` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `position` INTEGER NOT NULL,
  `url` TEXT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  CONSTRAINT `page_videos_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`)
);

CREATE INDEX IF NOT EXISTS `page_videos_page_id` ON `page_videos` (`page_id`);

CREATE TABLE IF NOT EXISTS `page_visits` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_id` INTEGER NOT NULL,
  `visited_at` INTEGER NOT NULL,
  CONSTRAINT `page_visits_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS `page_visits_page_visits_window` ON `page_visits` (`visited_at`,`page_id`);

CREATE INDEX IF NOT EXISTS `page_visits_page_id` ON `page_visits` (`page_id`);
