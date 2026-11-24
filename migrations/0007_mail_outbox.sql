CREATE TABLE worker_mail_outbox (
  id INTEGER PRIMARY KEY,
  operation TEXT NOT NULL,
  payload TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  next_attempt_at INTEGER NOT NULL
);

CREATE INDEX worker_mail_outbox_due ON worker_mail_outbox (next_attempt_at, id);
