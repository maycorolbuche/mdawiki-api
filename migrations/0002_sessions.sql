CREATE TABLE worker_sessions (
  token_hash TEXT PRIMARY KEY NOT NULL,
  csrf_token TEXT NOT NULL,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  password_fingerprint TEXT,
  expires_at INTEGER NOT NULL,
  CHECK ((user_id IS NULL) = (password_fingerprint IS NULL))
);
CREATE INDEX worker_sessions_expiration ON worker_sessions(expires_at);

CREATE TABLE worker_rate_limits (
  scope TEXT NOT NULL,
  window INTEGER NOT NULL,
  attempts INTEGER NOT NULL,
  PRIMARY KEY (scope, window)
);
CREATE INDEX worker_rate_limits_expiration ON worker_rate_limits(window);
