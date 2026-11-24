CREATE TABLE page_pending_images (
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  position INTEGER NOT NULL,
  identifier TEXT NOT NULL,
  legacy_identifier TEXT NOT NULL,
  is_template INTEGER NOT NULL,
  PRIMARY KEY (page_id, position)
);

CREATE INDEX page_pending_images_identifier ON page_pending_images(identifier);
CREATE INDEX page_pending_images_legacy_identifier ON page_pending_images(legacy_identifier);

-- Artigos anteriores já guardavam a marcação de imagem em page_templates.
-- template_key usa a grafia legada, com acentos normalizados e espaços em underscores.
WITH RECURSIVE
  refs AS (
    SELECT t.page_id, ROW_NUMBER() OVER (PARTITION BY t.page_id ORDER BY t.position, t.id) AS position,
      trim(substr(substr(t.template_key, 8) || '|', 1, instr(substr(t.template_key, 8) || '|', '|') - 1), '_ ') AS name
    FROM page_templates t JOIN pages p ON p.id = t.page_id
    WHERE t.template_key LIKE 'imagem:%' AND p.kind <> 'image' AND p.legacy_identifier NOT LIKE 'page@%'
  ),
  names AS (
    SELECT page_id, position,
      CASE WHEN substr(name, -5) = '!tmpl' THEN trim(substr(name, 1, length(name) - 5), '_ ') ELSE name END AS name,
      substr(name, -5) = '!tmpl' AS is_template
    FROM refs
  ),
  normalized AS (
    SELECT page_id, position, name, is_template, replace(name, '_', '-') AS identifier FROM names
    UNION ALL
    SELECT page_id, position, name, is_template, replace(identifier, '--', '-') FROM normalized
    WHERE instr(identifier, '--') > 0
  )
INSERT INTO page_pending_images (page_id, position, identifier, legacy_identifier, is_template)
SELECT page_id, position, trim(identifier, '-'), 'imagem@' || name, is_template
FROM normalized WHERE instr(identifier, '--') = 0 AND trim(identifier, '-') <> '';

-- Resolve também quando a imagem foi criada antes desta correção.
INSERT INTO page_media (page_id, image_id, position, is_template)
SELECT r.page_id, i.id, r.position, r.is_template
FROM page_pending_images r JOIN images i ON i.id = COALESCE(
  (SELECT id FROM images WHERE identifier = r.identifier AND access_level_id <= 1),
  (SELECT id FROM images WHERE legacy_identifier = r.legacy_identifier AND access_level_id <= 1)
)
WHERE NOT EXISTS (SELECT 1 FROM page_media m WHERE m.page_id = r.page_id AND m.image_id = i.id);

DELETE FROM page_pending_images AS r WHERE EXISTS (
  SELECT 1 FROM images i WHERE i.access_level_id <= 1
    AND (i.identifier = r.identifier OR i.legacy_identifier = r.legacy_identifier)
);

CREATE TRIGGER worker_link_pending_image AFTER INSERT ON images
WHEN NEW.access_level_id <= 1
BEGIN
  INSERT INTO page_media (page_id, image_id, position, is_template)
  SELECT r.page_id, NEW.id, r.position, r.is_template FROM page_pending_images r
  WHERE r.identifier = NEW.identifier OR r.legacy_identifier = NEW.legacy_identifier;

  DELETE FROM page_pending_images
  WHERE identifier = NEW.identifier OR legacy_identifier = NEW.legacy_identifier;
END;
