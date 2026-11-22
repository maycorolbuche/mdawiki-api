<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

function setting(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false && $default === null) {
        throw new RuntimeException("Variável obrigatória: {$name}");
    }
    return $value === false ? $default : $value;
}

function query(PDO $db, string $sql, array $values = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($values);
    return $statement;
}

/** Table and column names come exclusively from the import mappings. */
function prepareUpsert(PDO $db, string $table, array $columns, array $protected): PDOStatement
{
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $updates = array_map(
        static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_diff($columns, $protected),
    );
    $names = '`' . implode('`, `', $columns) . '`';
    return $db->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$placeholders})"
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates));
}

function upsert(PDO $db, string $table, array $row, array $keys): void
{
    prepareUpsert($db, $table, array_keys($row), $keys)->execute(array_values($row));
}

function legacyText(?string $value): ?string
{
    // With a latin1 connection, legacy text can contain existing UTF-8 bytes.
    if ($value === null || mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
}

function legacyDate(?string $value): ?string
{
    return $value === null || str_starts_with($value, '0000-00-00') ? null : $value;
}

function legacyFlag(?string $value): ?int
{
    return match ($value) {
        null => null,
        'S' => 1,
        'N' => 0,
        default => throw new RuntimeException('Flag legada fora do padrão S/N.'),
    };
}

function checksum(array $row): string
{
    return hash('sha256', serialize($row));
}

function fileId(PDO $target, string $root, string $folder, ?string $data): ?int
{
    if ($data === null || trim($data) === '') {
        return null;
    }

    // Accept legacy data URIs and plain Base64. Determine the MIME from the bytes.
    $encoded = trim($data);
    if (str_starts_with(strtolower($encoded), 'data:')) {
        if (!preg_match('~\Adata:image/[a-z0-9.+-]+;base64,~i', $encoded, $match)) {
            throw new RuntimeException('Data URI de imagem inválida.');
        }
        $encoded = substr($encoded, strlen($match[0]));
    }
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Base64 de imagem inválido.');
    }

    $size = @getimagesizefromstring($bytes);
    $mime = $size['mime'] ?? null;
    if ($mime === null) {
        $svg = new DOMDocument();
        if (!$svg->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
            || $svg->doctype !== null
            || $svg->documentElement?->localName !== 'svg') {
            throw new RuntimeException('Os bytes decodificados não são uma imagem reconhecida.');
        }
        $mime = 'image/svg+xml';
    }
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp', 'image/x-ms-bmp' => 'bmp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        default => throw new RuntimeException('Formato de imagem legado fora do conjunto suportado.'),
    };

    $hash = hash('sha256', $bytes);
    $directory = $root . '/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Falha ao criar diretório de mídia.');
    }
    $key = $folder . '/' . $hash . '.' . $extension;
    $file = query($target, 'SELECT id FROM files WHERE storage_key = ?', [$key])->fetch();

    $path = $root . '/' . $key;
    if (!is_file($path)) {
        $temporary = tempnam($directory, '.migration-');
        if ($temporary === false) {
            throw new RuntimeException('Falha ao criar arquivo temporário de mídia.');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes)
                || !chmod($temporary, 0640) || !rename($temporary, $path)) {
                throw new RuntimeException('Falha ao gravar a imagem.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    if (hash_file('sha256', $path) !== $hash) {
        throw new RuntimeException("Arquivo existente tem hash incorreto: {$key}");
    }

    if ($file !== false) {
        return (int) $file['id'];
    }
    query($target, 'INSERT INTO files (sha256, storage_key, mime_type, size_bytes) VALUES (?, ?, ?, ?)',
        [$hash, $key, $mime, strlen($bytes)]);

    return (int) $target->lastInsertId();
}

/** The canonical slug preserves the title's case and accents. */
function pageSlugs(?string $title, string $fallback): array
{
    return MDAWiki\PageSlugs::fromTitle($title, $fallback);
}

function validateDestinationSchema(PDO $target): void
{
    $legacyColumns = $target->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'comments' AND COLUMN_NAME = 'page_slug')
          OR (TABLE_NAME = 'page_categories' AND COLUMN_NAME = 'legacy_page_identifier')
          OR (UPPER(TABLE_NAME) = 'PAGINAS' AND UPPER(COLUMN_NAME) = 'NOME_URL'))")->fetchColumn();
    if ((int) $legacyColumns !== 0) {
        throw new RuntimeException('O destino tem o esquema anterior. Configure uma nova base de destino para importar com o esquema atual.');
    }
}

/** Legacy names are used only to find the new page IDs during import. */
function buildPageLookup(PDO $source, PDO $target): array
{
    $lookup = ['identifier' => [], 'slug' => []];
    foreach ($target->query('SELECT id, legacy_identifier FROM pages WHERE identifier IS NOT NULL') as $page) {
        $lookup['identifier'][$page['legacy_identifier']] = (int) $page['id'];
    }
    foreach ($source->query('SELECT IDENT, NOME_URL FROM PAGINAS') as $page) {
        $id = $lookup['identifier'][legacyText($page['IDENT'])] ?? null;
        $slug = legacyText($page['NOME_URL']);
        if ($id !== null && $slug !== null && $slug !== '') {
            $lookup['slug'][$slug] = array_key_exists($slug, $lookup['slug']) ? null : $id;
        }
    }

    return $lookup;
}

function migrateImage(PDO $source, PDO $target, string $root, array $row, array &$counts, array &$context): void
{
    $key = legacyText($row['IDENT']);
    $name = preg_replace('/^imagem@/iu', '', legacyText($row['NOME_URL']));
    $title = trim(preg_replace('/^imagem@/iu', '', legacyText($row['NOME']) ?? ''));
    [$slug, $identifier] = pageSlugs(null, $name);
    $image = query($target, 'SELECT id, legacy_identifier, file_id, meta FROM images WHERE identifier = ?', [$identifier])->fetch();
    if ($image && $image['legacy_identifier'] !== $key) {
        throw new RuntimeException('Identificador de imagem já utilizado.');
    }
    $context['field'] = 'CONTEUDO_CODE';
    $file = fileId($target, $root, 'articles', $row['CONTEUDO_CODE']);
    $storage = $file === null ? null : query($target, 'SELECT storage_key FROM files WHERE id = ?', [$file])->fetchColumn();
    $meta = $storage ? MDAWiki\NormalizedImages::properties($root, $storage) : [];
    // Reexecutar a importação não deve desfazer substituições feitas pelo editor.
    $replaced = $image && query($target, 'SELECT 1 FROM image_revisions WHERE image_id = ? AND legacy_revision_number IS NULL LIMIT 1',
        [$image['id']])->fetchColumn();
    upsert($target, 'images', ['identifier' => $identifier, 'slug' => $slug, 'title' => $title ?: str_replace('_', ' ', $name),
        'meta' => $replaced ? $image['meta'] : json_encode((object) $meta, JSON_THROW_ON_ERROR),
        'file_id' => $replaced ? $image['file_id'] : $file, 'legacy_identifier' => $key,
        'access_level_id' => $row['NIVEL'] ?? 1], ['identifier']);
    $imageId = $image['id'] ?? $target->lastInsertId();
    $counts[$image ? 'updated' : 'inserted']++;
    foreach (query($source, 'SELECT * FROM PAGINAS_LOGS WHERE IDENT = ? ORDER BY NOME_URL_ID', [$row['IDENT']]) as $revision) {
        $context = ['table' => 'PAGINAS_LOGS', 'key' => ['IDENT' => $key, 'NOME_URL_ID' => $revision['NOME_URL_ID']], 'field' => 'CONTEUDO_CODE'];
        upsert($target, 'image_revisions', ['image_id' => $imageId,
            'file_id' => fileId($target, $root, 'articles', $revision['CONTEUDO_CODE']),
            'legacy_revision_number' => (int) $revision['NOME_URL_ID'], 'access_level_id' => $revision['NIVEL'] ?? $row['NIVEL'] ?? 1, 'created_at' => legacyDate($revision['DATAHORA'])],
            ['image_id', 'legacy_revision_number']);
        $counts['revisions']++;
    }
}

function migratePage(PDO $source, PDO $target, string $root, array $row, array &$counts, array &$context): void
{
    $key = legacyText($row['IDENT']);
    $legacySlug = legacyText($row['NOME_URL']);
    $isImage = str_starts_with(strtolower($legacySlug), 'imagem@') || str_starts_with(strtolower($key), 'imagem@');
    if ($isImage) {
        migrateImage($source, $target, $root, $row, $counts, $context);
        return;
    }
    [$slug, $identifier] = pageSlugs(legacyText($row['NOME']), $legacySlug);
    if (mb_strlen($identifier) < 2) {
        throw new RuntimeException('Endereço de um caractere reservado para o sistema.');
    }
    $owner = query($target, 'SELECT id, legacy_identifier FROM pages WHERE identifier = ?', [$identifier])->fetch();
    if ($owner !== false && $owner['legacy_identifier'] !== $key) {
        if ($owner['legacy_identifier'] === '@category:' . hash('sha256', $identifier)
            && !query($target, 'SELECT id FROM page_revisions WHERE page_id = ? LIMIT 1', [$owner['id']])->fetch()) {
            query($target, 'UPDATE pages SET legacy_identifier = ? WHERE id = ?', [$key, $owner['id']]);
        } else {
            $context['field'] = 'NOME';
            throw new RuntimeException("Identificador normalizado em conflito: {$identifier} (página legada {$owner['legacy_identifier']}).");
        }
    }
    $updatedAt = legacyDate($row['DT_ALTERACAO']);
    $fingerprint = checksum($row);
    $current = query($target, 'SELECT id, slug, identifier, updated_at, legacy_checksum FROM pages WHERE legacy_identifier = ?', [$key])->fetch();

    if ($current !== false && $current['legacy_checksum'] !== $fingerprint
        && query($target, 'SELECT id FROM page_revisions WHERE page_id = ? AND legacy_revision_number < 0 LIMIT 1', [$current['id']])->fetch()) {
        throw new RuntimeException('Página editada no destino também foi alterada no legado.');
    }

    if ($current === false || $current['identifier'] !== $identifier || $current['slug'] !== $slug || $current['legacy_checksum'] !== $fingerprint) {
        upsert($target, 'pages', [
            'legacy_identifier' => $key,
            'slug' => $slug,
            'identifier' => $identifier,
            'title' => legacyText($row['NOME']),
            'kind' => 'article',
            'source' => legacyText($row['CONTEUDO_CODE']),
            'rendered_html' => legacyText($row['CONTEUDO']),
            'plain_text' => legacyText($row['CONTEUDO_TXT']),
            'is_searchable' => legacyFlag($row['BUSCA']),
            'access_level_id' => $row['NIVEL'],
            'needs_rebuild' => 1,
            'view_count' => $row['ACESSOS'],
            'is_html' => legacyFlag($row['HTML']),
            'is_sponsored' => legacyFlag($row['PATROCINADO']),
            'created_by_legacy' => legacyText($row['USU_CADASTRO']),
            'updated_by_legacy' => legacyText($row['USU_ALTERACAO']),
            'created_at' => legacyDate($row['DT_CADASTRO']),
            'updated_at' => $updatedAt,
            'rebuilt_at' => legacyDate($row['DT_ATUALIZACAO']),
            'legacy_checksum' => $fingerprint,
        ], ['legacy_identifier']);
        $counts[$current === false ? 'inserted' : 'updated']++;
    } else {
        $counts['unchanged']++;
    }

    $pageId = $current === false ? (int) $target->lastInsertId() : (int) $current['id'];
    $existing = query($target, 'SELECT legacy_revision_number, id, slug, revision_number, legacy_checksum
        FROM page_revisions WHERE page_id = ?', [$pageId])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $revisions = query($source, 'SELECT * FROM PAGINAS_LOGS WHERE IDENT = ? ORDER BY NOME_URL_ID', [$row['IDENT']]);
    foreach ($revisions as $revision) {
        $context = ['table' => 'PAGINAS_LOGS', 'key' => ['IDENT' => $key, 'NOME_URL_ID' => $revision['NOME_URL_ID']], 'field' => null];
        $legacyNumber = (int) $revision['NOME_URL_ID'];
        $prior = $existing[$legacyNumber] ?? null;
        $fingerprint = checksum($revision);
        [$revisionSlug] = pageSlugs(legacyText($revision['NOME']), legacyText($revision['NOME_URL']));
        if ($prior === null || $prior['slug'] !== $revisionSlug || $prior['legacy_checksum'] !== $fingerprint) {
            upsert($target, 'page_revisions', [
                'page_id' => $pageId,
                'legacy_revision_number' => $legacyNumber,
                'revision_number' => $prior['revision_number'] ?? 0,
                'slug' => $revisionSlug,
                'title' => legacyText($revision['NOME']),
                'source' => legacyText($revision['CONTEUDO_CODE']),
                'rendered_html' => legacyText($revision['CONTEUDO']),
                'is_searchable' => legacyFlag($revision['BUSCA']),
                'access_level_id' => $revision['NIVEL'],
                'editor_email' => legacyText($revision['EMAIL']),
                'legacy_user_id' => $revision['ID_USUARIO'],
                'is_expired' => legacyFlag($revision['EXPIRADO']),
                'created_at' => legacyDate($revision['DATAHORA']),
                'legacy_checksum' => $fingerprint,
            ], ['page_id', 'legacy_revision_number']);
            $counts['revisions']++;
        }
    }

    $number = 0;
    $order = query($target, 'SELECT id, revision_number FROM page_revisions
        WHERE page_id = ? ORDER BY legacy_revision_number < 0, ABS(legacy_revision_number)', [$pageId]);
    foreach ($order as $revision) {
        $number++;
        if ((int) $revision['revision_number'] !== $number) {
            query($target, 'UPDATE page_revisions SET revision_number = ? WHERE id = ?', [$number, $revision['id']]);
        }
    }
}

function applyDestinationSchema(PDO $target): void
{
    foreach (['001_content_and_media.sql', '002_remaining_legacy_tables.sql', '003_migration_errors.sql'] as $migration) {
        fwrite(STDOUT, "Aplicando {$migration}..." . PHP_EOL);
        $path = dirname(__DIR__) . '/migrations/' . $migration;
        $sql = is_readable($path) ? file_get_contents($path) : false;
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Não foi possível carregar a migration {$migration}.");
        }

        // These migrations contain only idempotent CREATE TABLE statements.
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $target->exec($statement);
            }
        }
    }
}

function recordMigrationError(PDO $target, string $runId, array $context, Throwable $error): void
{
    $message = $error instanceof PDOException
        ? 'Erro SQL ' . ($error->errorInfo[1] ?? $error->getCode())
        : $error->getMessage();
    query($target, 'INSERT INTO migration_errors
        (run_id, source_table, source_key, source_field, error_message) VALUES (?, ?, ?, ?, ?)', [
        $runId,
        $context['table'],
        json_encode($context['key'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        $context['field'],
        $message,
    ]);
}

function migrateRemainingTables(PDO $source, PDO $target, string $root, string $appKey, string $runId): int
{
    $errors = 0;
    $lookup = buildPageLookup($source, $target);
    $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $tables = require __DIR__ . '/legacy-table-map.php';
    foreach ($tables as $legacyTable => [$table, $keys, $fields]) {
        $users = $legacyTable === 'USUARIOS';
        $statement = null;
        $copied = 0;
        $failed = 0;
        $total = (int) $source->query("SELECT COUNT(*) FROM `{$legacyTable}`")->fetchColumn();
        fwrite(STDOUT, "Importando {$legacyTable} → {$table} ({$total} registros)..." . PHP_EOL);
        $columns = array_keys($fields);
        if ($users) {
            array_push($columns, 'FOTO_64', 'FOTO_32X32_64');
        }
        $result = $source->query('SELECT `' . implode('`, `', $columns) . "` FROM `{$legacyTable}`");
        foreach ($result as $row) {
            $context = ['table' => $legacyTable, 'key' => [], 'field' => null];
            try {
                $values = [];
                foreach ($fields as $origin => $definition) {
                    [$column, $type] = array_pad(explode(':', $definition, 2), 2, 'text');
                    if (in_array($column, $keys, true)) {
                        $context['key'][$origin] = is_string($row[$origin]) ? legacyText($row[$origin]) : $row[$origin];
                    }
                    $value = $row[$origin];
                    $values[$column] = match ($type) {
                        'number' => $value,
                        'date' => legacyDate($value),
                        'flag' => legacyFlag($value),
                        default => legacyText($value),
                    };
                }
                if ($table === 'comments') {
                    $values['page_id'] = $lookup['slug'][$values['page_slug']] ?? null;
                    unset($values['page_slug']);
                }
                $protected = $keys;
                if ($users) {
                    $target->beginTransaction();
                    $password = (string) $row['SENHA'];
                    $values['password_hash'] = match (true) {
                        preg_match('/^[a-f0-9]{32}$/D', $password) === 1 => $password,
                        strlen($appKey) >= 32 && preg_match('/^p2\$[a-f0-9]{29}$/D', $password) === 1 => $password,
                        default => '',
                    };
                    $protected[] = 'password_hash';
                    $context['field'] = 'FOTO_64';
                    $values['avatar_file_id'] = fileId($target, $root, 'avatars', $row['FOTO_64']);
                    $context['field'] = 'FOTO_32X32_64';
                    $values['thumbnail_file_id'] = fileId($target, $root, 'avatars', $row['FOTO_32X32_64']);
                    $context['field'] = null;
                }
                $statement ??= prepareUpsert($target, $table, array_keys($values), $protected);
                $statement->execute(array_values($values));
                if ($users) {
                    $target->commit();
                }
                $copied++;
            } catch (Throwable $error) {
                if ($target->inTransaction()) {
                    $target->rollBack();
                }
                recordMigrationError($target, $runId, $context, $error);
                $failed++;
                if ($failed <= 10) {
                    $message = $error instanceof PDOException ? 'Erro SQL ' . ($error->errorInfo[1] ?? $error->getCode()) : $error->getMessage();
                    fwrite(STDERR, "{$legacyTable}: {$message}" . PHP_EOL);
                }
            }
            $attempted = $copied + $failed;
            if ($attempted % 1000 === 0) {
                fwrite(STDOUT, "{$table}: {$attempted}/{$total} registros. Falhas: {$failed}." . PHP_EOL);
            }
        }
        $result->closeCursor();
        fwrite(STDOUT, "{$table}: {$copied} processados, {$failed} erros." . PHP_EOL);
        $errors += $failed;
    }

    return $errors;
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Uso: php bin/migrate-legacy.php\nConsulte docs/legacy-migration.md para configurar origem, destino e mídia.\n");
    exit(0);
}

$stage = 'Configuração';
try {
    if ($argc !== 1) {
        throw new RuntimeException('Argumento desconhecido. Use --help.');
    }
    if (!class_exists(Normalizer::class)) {
        throw new RuntimeException('Habilite a extensão PHP intl para normalizar os slugs.');
    }
    $config = require dirname(__DIR__) . '/config/bootstrap.php';
    $stage = 'Conexão com a origem legada (MIGRATION_DB_*)';
    fwrite(STDOUT, $stage . PHP_EOL);
    $source = MDAWiki\LegacyPageRepository::connect([
        'host' => setting('MIGRATION_DB_HOST', '127.0.0.1'),
        'port' => setting('MIGRATION_DB_PORT', '3306'),
        'database' => setting('MIGRATION_DB_DATABASE'),
        'username' => setting('MIGRATION_DB_USERNAME'),
        'password' => setting('MIGRATION_DB_PASSWORD', ''),
        'charset' => setting('MIGRATION_DB_CHARSET', 'latin1'),
    ], $config['public_max_level'])->connection();
    $stage = 'Conexão com a nova base (DB_*)';
    fwrite(STDOUT, $stage . PHP_EOL);
    $target = MDAWiki\LegacyPageRepository::connect(['charset' => 'utf8mb4'] + $config['db'], $config['public_max_level'])->connection();
    $stage = 'Validação das bases';
    $identity = 'SELECT CONCAT(@@hostname, "/", @@port, "/", @@server_id, "/", DATABASE())';
    $originIdentity = $source->query($identity)->fetchColumn();
    $targetIdentity = $target->query($identity)->fetchColumn();
    if (!$originIdentity || !$targetIdentity) {
        throw new RuntimeException('Informe o nome das duas bases de dados.');
    }
    if ($originIdentity === $targetIdentity) {
        throw new RuntimeException('Origem e destino apontam para a mesma base.');
    }
    $source->exec('SET SESSION TRANSACTION READ ONLY');
    $source->query('SELECT IDENT FROM PAGINAS LIMIT 0');
    $source->query('SELECT IDENT FROM PAGINAS_LOGS LIMIT 0');
    $target->exec('SET NAMES utf8mb4');
    $lock = 'mdawiki-migrate-' . substr(hash('sha256', (string) $target->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    if ((int) query($target, 'SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== 1) {
        throw new RuntimeException('Já existe uma migração executando nesse destino.');
    }
    $stage = 'Aplicação do esquema no destino';
    validateDestinationSchema($target);
    applyDestinationSchema($target);
    // Existing destinations used a unique hash across all media. The unique
    // storage key now deduplicates each media folder independently.
    if ($target->query("SHOW INDEX FROM files WHERE Key_name = 'sha256' AND Non_unique = 0")->fetch()) {
        $target->exec('ALTER TABLE files DROP INDEX sha256');
    }
    $runId = bin2hex(random_bytes(16));
    fwrite(STDOUT, "Execução: {$runId}" . PHP_EOL);
    $stage = 'Importação de conteúdo';

    $root = rtrim($config['media_root'], '/\\');
    if ($root === '' || (!is_dir($root) && !mkdir($root, 0770, true)) || !is_writable($root)) {
        throw new RuntimeException('Sem permissão de escrita no diretório de mídia do destino.');
    }
    $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'revisions' => 0, 'errors' => 0];

    $total = (int) $source->query('SELECT COUNT(*) FROM PAGINAS')->fetchColumn();
    fwrite(STDOUT, "Importando {$total} páginas, revisões e mídias..." . PHP_EOL);
    $attempted = 0;
    $after = null;
    while (true) {
        // Select only keys for batching to avoid loading multiple images at once.
        $keys = $after === null
            ? query($source, 'SELECT IDENT FROM PAGINAS ORDER BY IDENT LIMIT 100')->fetchAll(PDO::FETCH_COLUMN)
            : query($source, 'SELECT IDENT FROM PAGINAS WHERE IDENT > ? ORDER BY IDENT LIMIT 100', [$after])->fetchAll(PDO::FETCH_COLUMN);
        if ($keys === []) {
            break;
        }
        foreach ($keys as $after) {
            $attempted++;
            $row = query($source, 'SELECT * FROM PAGINAS WHERE IDENT = ?', [$after])->fetch();
            if ($row === false) {
                continue;
            }
            $pageCounts = $counts;
            $context = ['table' => 'PAGINAS', 'key' => ['IDENT' => legacyText($after)], 'field' => null];
            $target->beginTransaction();
            try {
                migratePage($source, $target, $root, $row, $pageCounts, $context);
                $target->commit();
                $counts = $pageCounts;
            } catch (Throwable $error) {
                if ($target->inTransaction()) {
                    $target->rollBack();
                }
                recordMigrationError($target, $runId, $context, $error);
                $counts['errors']++;
                fwrite(STDERR, 'Página ' . json_encode(legacyText($after), JSON_UNESCAPED_UNICODE)
                    . ': ' . ($error instanceof PDOException ? 'Erro SQL ' . $error->getCode() : $error->getMessage()) . PHP_EOL);
            }
        }
        fwrite(STDOUT, "Páginas: {$attempted}/{$total}. " . json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    $stage = 'Importação das tabelas complementares';
    $counts['table_errors'] = migrateRemainingTables($source, $target, $root, $config['app_key'], $runId);
    $stage = 'Reprocessamento final das páginas';
    $counts['rebuild_errors'] = MDAWiki\NormalizedPageRebuild::run(new MDAWiki\Database($target), $config);
    fwrite(STDOUT, 'Total: ' . json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($counts['errors'] + $counts['table_errors'] + $counts['rebuild_errors'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    $code = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? $error->getCode()) : 0;
    $message = match (true) {
        $code === 1045 => 'Erro SQL 1045. Acesso negado pelo MySQL. Confira usuário, senha e permissão de conexão a partir deste host.',
        $code === 1049 => 'Erro SQL 1049. Base inexistente. Confira o nome da base configurada.',
        $error instanceof PDOException => 'Erro SQL ' . $code,
        default => $error->getMessage(),
    };
    fwrite(STDERR, $stage . ': ' . $message . PHP_EOL);
    exit(1);
}
