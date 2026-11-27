RENAME TABLE page_templates TO page_markup_index;

CREATE TABLE `page_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `page_id` bigint(20) unsigned NOT NULL,
  `template` varchar(40) NOT NULL,
  `meta` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_template_type` (`page_id`, `template`),
  CONSTRAINT `page_templates_page_fk` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE page_revisions ADD COLUMN templates JSON DEFAULT NULL;
