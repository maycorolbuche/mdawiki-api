<?php
declare(strict_types=1);

use MDAWiki\{Database, LegacyPageRepository, NormalizedPageRebuild};

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require dirname(__DIR__) . '/config/bootstrap.php';
try {
    $pdo = LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection();
    $hasPages = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'identifier'");
    if ((int) $hasPages->fetchColumn() !== 1) {
        throw new RuntimeException('Configure DB_DATABASE para a base migrada.');
    }
    $pdo->exec('SET NAMES utf8mb4');
    exit(NormalizedPageRebuild::run(new Database($pdo), $config) > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
