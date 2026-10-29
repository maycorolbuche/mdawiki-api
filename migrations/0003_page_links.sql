CREATE TABLE page_links (
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  position INTEGER NOT NULL CHECK (position >= 0),
  href TEXT NOT NULL,
  link_text TEXT NOT NULL DEFAULT '',
  is_external INTEGER NOT NULL CHECK (is_external IN (0, 1)),
  target_slug TEXT,
  PRIMARY KEY (page_id, position)
);
CREATE INDEX page_links_target_slug ON page_links(target_slug) WHERE is_external = 0;
CREATE INDEX page_links_href ON page_links(href);
