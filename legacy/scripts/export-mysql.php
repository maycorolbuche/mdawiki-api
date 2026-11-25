<?php

declare(strict_types=1);

require_once __DIR__ . '/d1-export-sql.php';

// Read a consistent, read-only MySQL snapshot and emit data-only SQL for a fresh D1 database.
if (PHP_SAPI !== 'cli' || $argc !== 2 || getenv('MYSQL_USER') === false || getenv('MYSQL_USER') === '') {
    fwrite(STDERR, "Uso: informe MYSQL_USER, MYSQL_PASSWORD e MYSQL_HOST/MYSQL_DATABASE ou MYSQL_DSN.\n");
    exit(2);
}

$dsn = getenv('MYSQL_DSN');
if ($dsn === false || $dsn === '') {
    $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
    $port = filter_var(getenv('MYSQL_PORT') ?: '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $database = getenv('MYSQL_DATABASE') ?: '';
    if (!preg_match('/^[a-zA-Z0-9.:%_\[\]-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database) || $port === false) {
        fwrite(STDERR, "Informe MYSQL_DATABASE e confira MYSQL_HOST e MYSQL_PORT.\n");
        exit(2);
    }
    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
}

umask(0077);
$output = null;
$sqlitePath = null;
$created = false;
$table = 'arquivo de saída';
$count = 0;
$status = 0;
try {
    $output = fopen($argv[1], 'xb');
    if ($output === false) {
        throw new RuntimeException('Cannot create output file (it may already exist).');
    }
    $created = true;
    $table = 'validação SQLite';
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new DomainException('Driver pdo_sqlite ausente no PHP CLI. Confira php --ini e php -m.');
    }
    $sqlitePath = tempnam(sys_get_temp_dir(), 'mdawiki-d1-');
    if ($sqlitePath === false) {
        throw new RuntimeException('Cannot create temporary SQLite database.');
    }
    $target = new PDO('sqlite:' . $sqlitePath);
    $target->exec('PRAGMA foreign_keys = ON');
    $target->exec(file_get_contents(dirname(__DIR__, 2) . '/migrations/0001_schema.sql'));

    $table = 'conexão MySQL';
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new DomainException('Driver pdo_mysql ausente no PHP CLI. Confira php --ini e php -m.');
    }
    $source = new PDO($dsn, getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), [
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $table = 'schema MySQL';
    $normalized = $source->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME IN ('pages', 'users', 'images', 'files')")->fetchColumn();
    if ((int) $normalized !== 4) {
        throw new DomainException('A origem precisa ser o MySQL normalizado do MDAWiki, com pages, users, images e files.');
    }
    $table = 'transação MySQL';
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $target->beginTransaction();
    // sqlite_schema rowid retains the migration's table creation order and therefore FK order.
    $tables = $target->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")
        ->fetchAll(PDO::FETCH_COLUMN);
    $staging = false;
    $emit = static function (string $statement) use ($target, $output): void {
        if (strlen($statement) > 100000) {
            throw new DomainException('Row exceeds the D1 statement limit.');
        }
        $target->exec($statement);
        if (fwrite($output, $statement) !== strlen($statement)) {
            throw new RuntimeException('Incomplete write.');
        }
    };
    foreach ($tables as $table) {
        $count = 0;
        $columns = $target->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
        $names = implode(', ', array_map(static fn (array $col) => '`' . $col['name'] . '`', $columns));
        $rows = $source->query("SELECT $names FROM `$table`");
        foreach ($rows as $row) {
            ++$count;
            foreach (d1RowStatements($target, $table, $names, $columns, $row, $staging) as $statement) {
                $emit($statement);
            }
        }
        fwrite(STDOUT, "$table: $count\n");
    }
    if ($staging) {
        $emit("DROP TABLE _mdawiki_export_chunks;\n");
    }
    $target->commit();
    $source->exec('COMMIT');
    fflush($output);
    fwrite(STDOUT, 'SQLite validation size: ' . filesize($sqlitePath) . " bytes\n");
} catch (Throwable $failure) {
    // Driver errors may include data values or connection parameters.
    $reason = $failure instanceof DomainException ? $failure->getMessage() : get_class($failure);
    if ($failure instanceof PDOException) {
        $info = $failure->errorInfo ?? [];
        $state = (string) ($info[0] ?? $failure->getCode());
        $native = (string) ($info[1] ?? '');
        if ($native === '' && preg_match('/^SQLSTATE\[([A-Z0-9]{5})\]\s*\[(\d+)\]/', $failure->getMessage(), $match)) {
            [, $state, $native] = $match;
        }
        $hint = match ($native) {
            '1045' => 'Login recusado. Confira o usuário, a senha e a liberação do IP no MySQL.',
            '1044', '1142' => 'Permissão insuficiente. Confira o acesso de leitura ao banco.',
            '1049' => 'Banco não encontrado. Confira MYSQL_DATABASE.',
            '2002', '2003', '2005' => 'Conexão indisponível. Confira host, porta e acesso remoto ao MySQL.',
            '2026' => 'Falha TLS na conexão MySQL.',
            default => 'Erro retornado pelo driver PDO.',
        };
        $reason = trim(implode(' ', array_filter([
            preg_match('/^[A-Z0-9]{5}$/D', $state) ? 'SQLSTATE=' . $state : '',
            preg_match('/^\d{1,6}$/D', $native) ? 'driver=' . $native : '',
            $hint,
        ])));
    }
    fwrite(STDERR, "Export failed in $table after $count rows ($reason).\n");
    if (is_resource($output)) fclose($output);
    if ($created) unlink($argv[1]);
    $status = 1;
} finally {
    unset($target);
    if (is_string($sqlitePath)) unlink($sqlitePath);
    if (is_resource($output)) fclose($output);
}

exit($status);
