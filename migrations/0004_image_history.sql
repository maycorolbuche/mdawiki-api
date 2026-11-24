-- Uma única alteração de arquivo grava o histórico e sinaliza páginas vinculadas.
CREATE TRIGGER worker_image_revision AFTER UPDATE OF file_id ON images
WHEN OLD.file_id IS NOT NEW.file_id
BEGIN
  INSERT INTO image_revisions (image_id, file_id, created_at, access_level_id, created_by)
  SELECT OLD.id, OLD.file_id, OLD.updated_at, OLD.access_level_id, COALESCE(OLD.updated_by, OLD.created_by)
  WHERE OLD.file_id IS NOT NULL;

  INSERT INTO page_rebuild_queue (page_id, queued_at)
  SELECT DISTINCT p.id, CURRENT_TIMESTAMP
  FROM pages p JOIN page_media m ON m.page_id = p.id
  WHERE m.image_id = OLD.id AND p.kind <> 'image' AND p.legacy_identifier NOT LIKE 'page@%'
  ON CONFLICT (page_id) DO UPDATE SET queued_at = excluded.queued_at;
END;
