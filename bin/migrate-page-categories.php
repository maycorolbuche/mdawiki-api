<?php
declare(strict_types=1);

use MDAWiki\{Database, LegacyPageRepository, NormalizedCategoryMigration};

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require dirname(__DIR__) . '/config/bootstrap.php';
try {
    $pdo = LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection();
    $pdo->exec('SET NAMES utf8mb4');
    exit(NormalizedCategoryMigration::run(new Database($pdo)) > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
