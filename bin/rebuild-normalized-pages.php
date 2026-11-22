<?php
declare(strict_types=1);

use MDAWiki\{Accounts, Database, LegacyPageRepository, NormalizedPages, Session, WikiRenderer};

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
    $db = new Database($pdo);
    $config['normalized_editor'] = true;
    $config['normalized_read_only'] = true;
    $pages = new NormalizedPages($db, new Accounts($db, new Session(), $config, static fn () => null), new WikiRenderer($db, $config));
    $after = 0;
    $processed = 0;
    $failed = 0;
    do {
        $rows = $db->rows("SELECT id, slug FROM pages WHERE id > ? AND kind <> 'image'
            AND legacy_identifier NOT LIKE 'page@%' ORDER BY id LIMIT 100", [$after]);
        foreach ($rows as $row) {
            $after = (int) $row['id'];
            try {
                $pages->rebuildCache($row['slug']);
                $processed++;
            } catch (Throwable $error) {
                $failed++;
                fwrite(STDERR, $row['slug'] . ': ' . $error->getMessage() . "\n");
            }
        }
    } while ($rows);
    echo "Páginas reprocessadas: {$processed}. Falhas: {$failed}.\n";
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
