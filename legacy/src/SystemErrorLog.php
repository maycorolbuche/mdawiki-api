<?php
declare(strict_types=1);

namespace MDAWiki;

use Closure;
use Throwable;

final class SystemErrorLog
{
    public function __construct(private Closure $connect) {}

    public static function fromConfig(array $config): self
    {
        // Independent connection so failed account operations cannot roll back the log.
        return new self(static fn (): Database => new Database(
            LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection()
        ));
    }

    public function record(string $component, string $operation, string $code, string $message, array $context = []): void
    {
        try {
            ($this->connect)()->run(
                'INSERT INTO system_error_logs (component, operation, error_code, message, context) VALUES (?, ?, ?, ?, ?)',
                [$component, $operation, $code, $message, json_encode($context, JSON_THROW_ON_ERROR)]
            );
        } catch (Throwable) {
            error_log("MDAWiki: não foi possível gravar system_error_logs ({$component}/{$operation}/{$code}).");
        }
    }
}
