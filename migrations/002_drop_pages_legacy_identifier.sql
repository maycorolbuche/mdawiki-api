-- Preserva os endereços antigos e classifica as páginas internas antes de excluir a coluna.
CREATE TABLE IF NOT EXISTS page_aliases (
  alias varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  page_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY (alias),
  KEY page_aliases_page_id (page_id),
  CONSTRAINT page_aliases_page_fk FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE pages SET kind = 'system' WHERE legacy_identifier LIKE 'page@%';

INSERT INTO page_aliases (alias, page_id)
SELECT p.legacy_identifier, p.id FROM pages p
LEFT JOIN page_aliases a ON a.alias = p.legacy_identifier
WHERE a.alias IS NULL AND p.kind <> 'system' AND p.legacy_identifier <> p.identifier
  AND p.legacy_identifier NOT LIKE '@article:%' AND p.legacy_identifier NOT LIKE '@category:%';

ALTER TABLE pages DROP INDEX legacy_identifier, DROP COLUMN legacy_identifier;
