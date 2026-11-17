<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MDAWiki\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Hosting can set environment variables, or use an untracked file outside public/.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) && getenv($key) === false) {
            putenv($key . '=' . trim(trim($value), "\"'"));
        }
    }
}

return [
    'origins' => array_values(array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: '')))),
    'frontend_url' => rtrim(getenv('FRONTEND_URL') ?: 'https://mda.wiki.br', '/'),
    'media_url' => rtrim(getenv('LEGACY_MEDIA_URL') ?: 'https://mda.wiki.br/site', '/'),
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: '',
        'username' => getenv('DB_USERNAME') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'latin1',
    ],
    'public_max_level' => (int) (getenv('PUBLIC_MAX_LEVEL') ?: 1),
];
