<?php

declare(strict_types=1);

// Read a consistent, read-only MySQL snapshot and emit data-only SQL for a fresh D1 database.
if (PHP_SAPI !== 'cli' || $argc !== 2 || !getenv('MYSQL_DSN') || !getenv('MYSQL_USER')) {
    fwrite(STDERR, "Usage: MYSQL_DSN=... MYSQL_USER=... MYSQL_PASSWORD=... php export-mysql.php data.sql\n");
    exit(2);
}

umask(0077);
$output = null;
$sqlitePath = null;
$created = false;
$table = 'setup';
$count = 0;
$status = 0;
try {
    $output = fopen($argv[1], 'xb');
    if ($output === false) {
        throw new RuntimeException('Cannot create output file (it may already exist).');
    }
    $created = true;
    $sqlitePath = tempnam(sys_get_temp_dir(), 'mdawiki-d1-');
    if ($sqlitePath === false) {
        throw new RuntimeException('Cannot create temporary SQLite database.');
    }
    $target = new PDO('sqlite:' . $sqlitePath);
    $target->exec('PRAGMA foreign_keys = ON');
    $target->exec(file_get_contents(dirname(__DIR__, 2) . '/migrations/0001_schema.sql'));

    $source = new PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD') ?: '', [
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $target->beginTransaction();
    // sqlite_schema rowid retains the migration's table creation order and therefore FK order.
    $tables = $target->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $count = 0;
        $columns = $target->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
        $names = implode(', ', array_map(static fn (array $col) => '`' . $col['name'] . '`', $columns));
        $rows = $source->query("SELECT $names FROM `$table`");
        foreach ($rows as $row) {
            ++$count;
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column['name']];
                $values[] = match (true) {
                    $value === null => 'NULL',
                    $column['type'] === 'INTEGER' => integerLiteral($value),
                    str_contains($value, "\0") => "CAST(X'" . bin2hex($value) . "' AS TEXT)",
                    default => $target->quote($value),
                };
            }
            $statement = "INSERT INTO `$table` ($names) VALUES (" . implode(', ', $values) . ");\n";
            if (strlen($statement) > 100000) {
                throw new DomainException('Row exceeds the D1 statement limit.');
            }
            $target->exec($statement);
            if (fwrite($output, $statement) !== strlen($statement)) {
                throw new RuntimeException('Incomplete write.');
            }
        }
        fwrite(STDOUT, "$table: $count\n");
    }
    $target->commit();
    $source->exec('COMMIT');
    fflush($output);
    fwrite(STDOUT, 'SQLite validation size: ' . filesize($sqlitePath) . " bytes\n");
} catch (Throwable $failure) {
    // Driver errors may include data values or connection parameters.
    $reason = $failure instanceof DomainException ? $failure->getMessage() : get_class($failure);
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

/** D1 bindings use JS numbers. Reject imported integers that the Worker cannot read exactly. */
function integerLiteral(string $value): string
{
    if (!preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) || abs((float) $value) > 9007199254740991) {
        throw new DomainException('Integer outside the exact JavaScript range.');
    }
    return $value;
}
