-- Execute the WHOLE file in one connection after selecting the wiki database.
-- Default: simulation. Change ONLY 0 to 1 below to delete duplicates.
-- Every run needs unused backup/work table names. Change _01 everywhere to _02, etc.
-- Never drop an existing backup just to rerun this file.
SET @mdawiki_apply_cleanup = 0;

-- Deliberately no IF NOT EXISTS: a previous backup must never be overwritten.
CREATE TABLE MDAWIKI_HISTORY_BACKUP_01 LIKE PAGINAS_LOGS;
CREATE INDEX mdawiki_history_order ON MDAWIKI_HISTORY_BACKUP_01 (IDENT, NOME_URL_ID);
CREATE TABLE MDAWIKI_HISTORY_DUPLICATES_01 AS
    SELECT IDENT, NOME_URL_ID FROM PAGINAS_LOGS WHERE 1 = 0;
ALTER TABLE MDAWIKI_HISTORY_DUPLICATES_01 ADD PRIMARY KEY (IDENT, NOME_URL_ID);

-- Blocks concurrent history writes while the snapshot and deletion are made.
-- Aliases are explicitly locked as required by MySQL LOCK TABLES.
LOCK TABLES
    PAGINAS_LOGS WRITE,
    PAGINAS_LOGS AS live_history WRITE,
    MDAWIKI_HISTORY_BACKUP_01 WRITE,
    MDAWIKI_HISTORY_BACKUP_01 AS current_revision READ,
    MDAWIKI_HISTORY_BACKUP_01 AS previous_revision READ,
    MDAWIKI_HISTORY_BACKUP_01 AS earlier_revision READ,
    MDAWIKI_HISTORY_DUPLICATES_01 WRITE,
    MDAWIKI_HISTORY_DUPLICATES_01 AS duplicate_revision READ;

INSERT INTO MDAWIKI_HISTORY_BACKUP_01 SELECT * FROM PAGINAS_LOGS;

-- Compare the IMMEDIATELY preceding revision of the SAME article.
-- Binary equality preserves differences in case, accents, spaces and newlines.
-- Null is equal only to null. A -> B -> A is never collapsed.
INSERT INTO MDAWIKI_HISTORY_DUPLICATES_01 (IDENT, NOME_URL_ID)
SELECT current_revision.IDENT, current_revision.NOME_URL_ID
FROM MDAWIKI_HISTORY_BACKUP_01 AS current_revision
JOIN MDAWIKI_HISTORY_BACKUP_01 AS previous_revision
  ON previous_revision.IDENT = current_revision.IDENT
 AND previous_revision.NOME_URL_ID = (
    SELECT MAX(earlier_revision.NOME_URL_ID)
    FROM MDAWIKI_HISTORY_BACKUP_01 AS earlier_revision
    WHERE earlier_revision.IDENT = current_revision.IDENT
      AND earlier_revision.NOME_URL_ID < current_revision.NOME_URL_ID
 )
WHERE CAST(current_revision.NOME AS BINARY) <=> CAST(previous_revision.NOME AS BINARY)
  AND CAST(current_revision.CONTEUDO_CODE AS BINARY) <=> CAST(previous_revision.CONTEUDO_CODE AS BINARY);

SELECT IDENT AS artigo, COUNT(*) AS revisoes_duplicadas
FROM MDAWIKI_HISTORY_DUPLICATES_01 GROUP BY IDENT ORDER BY revisoes_duplicadas DESC, artigo;
SELECT @mdawiki_apply_cleanup AS executar,
       (SELECT COUNT(*) FROM MDAWIKI_HISTORY_BACKUP_01) AS historico_antes,
       (SELECT COUNT(*) FROM MDAWIKI_HISTORY_DUPLICATES_01) AS duplicadas;

DELETE live_history
FROM PAGINAS_LOGS AS live_history
JOIN MDAWIKI_HISTORY_DUPLICATES_01 AS duplicate_revision
  ON duplicate_revision.IDENT = live_history.IDENT
 AND duplicate_revision.NOME_URL_ID = live_history.NOME_URL_ID
WHERE @mdawiki_apply_cleanup = 1;
SELECT ROW_COUNT() AS revisoes_removidas;
SELECT COUNT(*) AS historico_depois FROM PAGINAS_LOGS;
UNLOCK TABLES;
