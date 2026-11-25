<?php

declare(strict_types=1);

/** Gera instruções para uma linha e usa uma tabela auxiliar quando o INSERT excede o limite. */
function d1RowStatements(PDO $sqlite, string $table, string $names, array $columns, array $row, bool &$staging): iterable
{
    if (array_sum(array_map(static fn ($value) => strlen((string) $value), $row)) > 2000000) {
        throw new DomainException('Os dados da linha excedem o limite de 2 MB do D1.');
    }

    $values = [];
    foreach ($columns as $column) {
        $name = $column['name'];
        $value = $row[$name];
        $values[$name] = match (true) {
            $value === null => 'NULL',
            $column['type'] === 'INTEGER' => integerLiteral($value),
            str_contains($value, "\0") => "CAST(X'" . bin2hex($value) . "' AS TEXT)",
            default => $sqlite->quote($value),
        };
    }
    $prefix = "INSERT INTO `$table` ($names) VALUES (";
    $insert = $prefix . implode(', ', $values) . ");\n";
    if (strlen($insert) <= 100000) {
        yield $insert;
        return;
    }

    if (!$staging) {
        yield "CREATE TABLE _mdawiki_export_chunks (id INTEGER PRIMARY KEY, value BLOB NOT NULL);\n";
        $staging = true;
    }

    // Mantém todos os campos de texto na tabela auxiliar até que a linha completa possa ser validada.
    $slot = 0;
    foreach ($columns as $column) {
        $name = $column['name'];
        $value = $row[$name];
        if ($column['type'] === 'INTEGER' || $value === null || $value === '') {
            continue;
        }

        ++$slot;
        yield "INSERT INTO _mdawiki_export_chunks (id, value) VALUES ($slot, X'');\n";
        for ($offset = 0, $length = strlen($value); $offset < $length; $offset += 45000) {
            // Hexadecimal preserva os bytes mesmo quando o corte divide um caractere UTF-8.
            $hex = bin2hex(substr($value, $offset, 45000));
            yield "UPDATE _mdawiki_export_chunks SET value = CAST(value || X'$hex' AS BLOB) WHERE id = $slot;\n";
        }
        $values[$name] = "(SELECT CAST(value AS TEXT) FROM _mdawiki_export_chunks WHERE id = $slot)";
    }

    yield $prefix . implode(', ', $values) . ");\n";
    yield "DELETE FROM _mdawiki_export_chunks;\n";
}

/** D1 bindings use JS numbers. Reject imported integers that the Worker cannot read exactly. */
function integerLiteral(string $value): string
{
    if (!preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) || abs((float) $value) > 9007199254740991) {
        throw new DomainException('Integer outside the exact JavaScript range.');
    }
    return $value;
}
