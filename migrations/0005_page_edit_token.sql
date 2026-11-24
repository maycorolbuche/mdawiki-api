ALTER TABLE pages ADD COLUMN worker_edit_token TEXT;
CREATE UNIQUE INDEX worker_page_edit_token ON pages(worker_edit_token);
