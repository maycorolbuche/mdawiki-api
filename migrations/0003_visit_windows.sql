CREATE TABLE worker_visit_windows (
  session_hash TEXT NOT NULL REFERENCES worker_sessions(token_hash) ON DELETE CASCADE,
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  visited_at INTEGER NOT NULL,
  PRIMARY KEY (session_hash, page_id)
);

-- O registro e o contador mudam na mesma transação do INSERT/UPSERT.
CREATE TRIGGER worker_visit_first AFTER INSERT ON worker_visit_windows BEGIN
  INSERT INTO page_visits (page_id, visited_at) VALUES (NEW.page_id, NEW.visited_at);
  UPDATE pages SET view_count = COALESCE(view_count, 0) + 1 WHERE id = NEW.page_id;
END;

CREATE TRIGGER worker_visit_again AFTER UPDATE OF visited_at ON worker_visit_windows BEGIN
  INSERT INTO page_visits (page_id, visited_at) VALUES (NEW.page_id, NEW.visited_at);
  UPDATE pages SET view_count = COALESCE(view_count, 0) + 1 WHERE id = NEW.page_id;
END;
