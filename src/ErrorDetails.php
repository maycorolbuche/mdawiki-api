<?php
declare(strict_types=1);

namespace MDAWiki;

use PDOException;
use Throwable;

final class ErrorDetails
{
    public static function describe(Throwable $error, string $stage, bool $debug, array $config): array
    {
        $details = ['stage' => $stage, 'layer' => 'application', 'reason' => 'unexpected_error'];
        if ($error instanceof PDOException) {
            $info = $error->errorInfo ?? [];
            $state = (string) ($info[0] ?? $error->getCode());
            $driver = (int) ($info[1] ?? 0);
            $reason = match (true) {
                str_contains(strtolower($error->getMessage()), 'could not find driver') => 'database_driver_missing',
                $driver === 1049 => 'database_not_found',
                $driver === 1045 || $state === '28000' => 'database_access_denied',
                $state === '42S02' => 'database_table_not_found',
                $state === '42S22' => 'database_column_not_found',
                in_array($driver, [2002, 2003, 2006, 2013], true) || str_starts_with($state, '08') => 'database_connection_failed',
                default => 'database_query_failed',
            };
            $details += ['sqlstate' => $state, 'driver_code' => $driver];
            $details['layer'] = 'database';
            $details['reason'] = $reason;
        } elseif ($error instanceof \InvalidArgumentException && $stage === 'database_connection') {
            $details['layer'] = 'configuration';
            $details['reason'] = 'invalid_database_configuration';
        }
        if ($debug) {
            // Keep the original exception message, but never serialize the config/DSN.
            $message = $error->getMessage();
            $password = $config['db']['password'] ?? '';
            if ($password !== '') {
                $message = str_replace($password, '[REDACTED]', $message);
            }
            $message = preg_replace('/(password|passwd|pwd)\s*[=:]\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s;]+)/i', '$1=[REDACTED]', $message);
            $details['type'] = get_class($error);
            $details['message'] = $message;
        }
        return $details;
    }
}
