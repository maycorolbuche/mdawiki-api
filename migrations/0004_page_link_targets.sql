ALTER TABLE page_links ADD COLUMN target_page_id INTEGER REFERENCES pages(id) ON DELETE SET NULL;
CREATE INDEX page_links_target_page_id ON page_links(target_page_id);
UPDATE page_links SET href = '/' || target_slug WHERE is_external = 0 AND target_slug IS NOT NULL;
UPDATE page_links SET target_page_id = (SELECT CASE WHEN p.type = 'redirect' THEN d.id ELSE p.id END FROM pages p LEFT JOIN pages d ON d.id = CAST(json_extract(p.metadata, '$.redirect') AS INTEGER) AND d.type <> 'redirect' WHERE p.slug = page_links.target_slug) WHERE is_external = 0;
CREATE TRIGGER page_links_resolve_insert AFTER INSERT ON page_links
BEGIN
  UPDATE page_links SET target_page_id = CASE WHEN is_external = 0 THEN (SELECT CASE WHEN p.type = 'redirect' THEN d.id ELSE p.id END FROM pages p LEFT JOIN pages d ON d.id = CAST(json_extract(p.metadata, '$.redirect') AS INTEGER) AND d.type <> 'redirect' WHERE p.slug = page_links.target_slug) ELSE NULL END WHERE page_id = NEW.page_id AND position = NEW.position;
END;
CREATE TRIGGER page_links_resolve_update AFTER UPDATE OF target_slug, is_external ON page_links
BEGIN
  UPDATE page_links SET target_page_id = CASE WHEN is_external = 0 THEN (SELECT CASE WHEN p.type = 'redirect' THEN d.id ELSE p.id END FROM pages p LEFT JOIN pages d ON d.id = CAST(json_extract(p.metadata, '$.redirect') AS INTEGER) AND d.type <> 'redirect' WHERE p.slug = page_links.target_slug) ELSE NULL END WHERE page_id = NEW.page_id AND position = NEW.position;
END;
CREATE TRIGGER pages_resolve_links_insert AFTER INSERT ON pages
BEGIN
  UPDATE page_links SET target_page_id = (SELECT CASE WHEN p.type = 'redirect' THEN d.id ELSE p.id END FROM pages p LEFT JOIN pages d ON d.id = CAST(json_extract(p.metadata, '$.redirect') AS INTEGER) AND d.type <> 'redirect' WHERE p.slug = page_links.target_slug) WHERE is_external = 0 AND target_slug = NEW.slug;
END;
CREATE TRIGGER pages_resolve_links_update AFTER UPDATE OF slug, type, metadata ON pages
BEGIN
  UPDATE page_links SET target_page_id = (SELECT CASE WHEN p.type = 'redirect' THEN d.id ELSE p.id END FROM pages p LEFT JOIN pages d ON d.id = CAST(json_extract(p.metadata, '$.redirect') AS INTEGER) AND d.type <> 'redirect' WHERE p.slug = page_links.target_slug) WHERE is_external = 0 AND target_slug = NEW.slug;
END;
CREATE TRIGGER pages_clear_alias_links AFTER DELETE ON pages
BEGIN
  UPDATE page_links SET target_page_id = NULL WHERE is_external = 0 AND target_slug = OLD.slug;
END;
