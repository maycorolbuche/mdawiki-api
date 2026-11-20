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

function connectDestination(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        setting('MIGRATION_DB_HOST', '127.0.0.1'),
        setting('MIGRATION_DB_PORT', '3306'),
        setting('MIGRATION_DB_DATABASE'),
    );

    return new PDO($dsn, setting('MIGRATION_DB_USERNAME'), setting('MIGRATION_DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
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

function migratePage(PDO $source, PDO $target, string $root, array $row, array &$counts, array &$context): void
{
    $key = legacyText($row['IDENT']);
    $slug = legacyText($row['NOME_URL']);
    $isImage = str_starts_with(strtolower($slug), 'imagem@') || str_starts_with(strtolower($key), 'imagem@');
    $updatedAt = legacyDate($row['DT_ALTERACAO']);
    $fingerprint = checksum($row);
    $current = query($target, 'SELECT id, updated_at, legacy_checksum FROM pages WHERE legacy_identifier = ?', [$key])->fetch();

    if ($current === false || $current['updated_at'] !== $updatedAt || $current['legacy_checksum'] !== $fingerprint) {
        upsert($target, 'pages', [
            'legacy_identifier' => $key,
            'slug' => $slug,
            'title' => legacyText($row['NOME']),
            'kind' => $isImage ? 'image' : 'article',
            'source' => $isImage ? null : legacyText($row['CONTEUDO_CODE']),
            'rendered_html' => $isImage ? null : legacyText($row['CONTEUDO']),
            'plain_text' => $isImage ? null : legacyText($row['CONTEUDO_TXT']),
            'is_searchable' => legacyFlag($row['BUSCA']),
            'access_level_id' => $row['NIVEL'],
            'needs_rebuild' => legacyFlag($row['ATUALIZAR']),
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
    $imageId = null;
    if ($isImage) {
        $context['field'] = 'CONTEUDO_CODE';
        upsert($target, 'images', ['page_id' => $pageId, 'file_id' => fileId($target, $root, 'articles', $row['CONTEUDO_CODE'])], ['page_id']);
        $imageId = (int) query($target, 'SELECT id FROM images WHERE page_id = ?', [$pageId])->fetchColumn();
    }

    $existing = query($target, 'SELECT legacy_revision_number, id, revision_number, legacy_checksum
        FROM page_revisions WHERE page_id = ?', [$pageId])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $revisions = query($source, 'SELECT * FROM PAGINAS_LOGS WHERE IDENT = ? ORDER BY NOME_URL_ID', [$row['IDENT']]);
    foreach ($revisions as $revision) {
        $context = ['table' => 'PAGINAS_LOGS', 'key' => ['IDENT' => $key, 'NOME_URL_ID' => $revision['NOME_URL_ID']], 'field' => $isImage ? 'CONTEUDO_CODE' : null];
        $legacyNumber = (int) $revision['NOME_URL_ID'];
        $prior = $existing[$legacyNumber] ?? null;
        $fingerprint = checksum($revision);
        if ($prior === null || $prior['legacy_checksum'] !== $fingerprint) {
            upsert($target, 'page_revisions', [
                'page_id' => $pageId,
                'legacy_revision_number' => $legacyNumber,
                'revision_number' => $prior['revision_number'] ?? 0,
                'slug' => legacyText($revision['NOME_URL']),
                'title' => legacyText($revision['NOME']),
                'source' => $isImage ? null : legacyText($revision['CONTEUDO_CODE']),
                'rendered_html' => $isImage ? null : legacyText($revision['CONTEUDO']),
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

        if ($imageId !== null) {
            $revisionId = $prior === null ? (int) $target->lastInsertId() : (int) $prior['id'];
            upsert($target, 'image_revisions', [
                'page_revision_id' => $revisionId,
                'image_id' => $imageId,
                'file_id' => fileId($target, $root, 'articles', $revision['CONTEUDO_CODE']),
            ], ['page_revision_id']);
        }
    }

    $number = 0;
    $order = query($target, 'SELECT id, revision_number FROM page_revisions
        WHERE page_id = ? ORDER BY legacy_revision_number', [$pageId]);
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
    $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $tables = require __DIR__ . '/legacy-table-map.php';
    foreach ($tables as $legacyTable => [$table, $keys, $fields]) {
        $users = $legacyTable === 'USUARIOS';
        $keyless = $keys === ['source_hash', 'source_occurrence'];
        $occurrences = [];
        $statement = null;
        $copied = 0;
        $failed = 0;
        fwrite(STDOUT, "Importando {$legacyTable} → {$table}..." . PHP_EOL);
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
                if ($keyless) {
                    $hash = checksum($row);
                    $occurrences[$hash] = ($occurrences[$hash] ?? 0) + 1;
                    $values['source_hash'] = $hash;
                    $values['source_occurrence'] = $occurrences[$hash];
                    $context['key'] = ['hash' => $hash, 'occurrence' => $occurrences[$hash]];
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
    $config = require dirname(__DIR__) . '/config/bootstrap.php';
    $stage = 'Conexão com a origem (DB_*)';
    $source = MDAWiki\LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection();
    $stage = 'Conexão com o destino (MIGRATION_DB_*)';
    $target = connectDestination();
    $stage = 'Validação das bases';
    $identity = 'SELECT CONCAT(@@hostname, "/", @@port, "/", @@server_id, "/", DATABASE())';
    $originIdentity = $source->query($identity)->fetchColumn();
    $targetIdentity = $target->query($identity)->fetchColumn();
    if ($originIdentity === null || $targetIdentity === null) {
        throw new RuntimeException('Informe dbname nos dois DSNs.');
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
        fwrite(STDOUT, json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    $stage = 'Importação das tabelas complementares';
    $counts['table_errors'] = migrateRemainingTables($source, $target, $root, $config['app_key'], $runId);
    fwrite(STDOUT, 'Total: ' . json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($counts['errors'] + $counts['table_errors'] === 0 ? 0 : 1);
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
