ALTER TABLE
    page_revisions
ADD
    COLUMN category_ids JSON DEFAULT NULL;

ALTER TABLE
    `page_revisions` CHANGE COLUMN `category_ids` `category_ids` JSON NULL DEFAULT NULL
AFTER
    `rendered_html`;