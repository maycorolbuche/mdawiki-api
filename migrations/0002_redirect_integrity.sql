-- Redirects share the pages namespace and point directly to an ordinary page ID.
CREATE TRIGGER pages_redirect_insert BEFORE INSERT ON pages WHEN NEW.type='redirect'
BEGIN
  SELECT CASE WHEN json_type(NEW.metadata,'$.redirect') IS NOT 'integer'
    OR NOT EXISTS(SELECT 1 FROM pages WHERE id=json_extract(NEW.metadata,'$.redirect') AND type<>'redirect')
    THEN RAISE(ABORT,'invalid redirect destination') END;
END;
CREATE TRIGGER pages_redirect_update BEFORE UPDATE ON pages WHEN NEW.type='redirect'
BEGIN
  SELECT CASE WHEN json_type(NEW.metadata,'$.redirect') IS NOT 'integer'
    OR NEW.id=json_extract(NEW.metadata,'$.redirect')
    OR NOT EXISTS(SELECT 1 FROM pages WHERE id=json_extract(NEW.metadata,'$.redirect') AND type<>'redirect')
    THEN RAISE(ABORT,'invalid redirect destination') END;
END;
CREATE TRIGGER pages_redirect_cleanup BEFORE DELETE ON pages
BEGIN
  DELETE FROM pages WHERE type='redirect' AND json_extract(metadata,'$.redirect')=OLD.id;
END;
