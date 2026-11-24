CREATE TABLE worker_email_changes (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  email TEXT NOT NULL COLLATE NOCASE,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL
);
