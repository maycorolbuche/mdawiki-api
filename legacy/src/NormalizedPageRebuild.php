<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedPageRebuild
{
    public static function run(Database $db, array $config): int
    {
        $config['normalized_editor'] = true;
        $config['normalized_read_only'] = true;
        $pages = new NormalizedPages($db, new Accounts($db, new Session(), $config, static fn () => null), new WikiRenderer($db, $config));
        $filter = "kind <> 'image' AND legacy_identifier NOT LIKE 'page@%'";
        $total = (int) $db->one("SELECT COUNT(*) AS total FROM pages WHERE $filter")['total'];
        fwrite(STDOUT, "Reprocessando {$total} páginas e seus vínculos..." . PHP_EOL);
        $after = 0;
        $processed = 0;
        $failed = 0;
        while ($rows = $db->rows("SELECT id, slug FROM pages WHERE id > ? AND $filter ORDER BY id LIMIT 100", [$after])) {
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                try {
                    $pages->rebuildCache($row['slug']);
                    $processed++;
                } catch (\Throwable $error) {
                    $failed++;
                    fwrite(STDERR, $row['slug'] . ': ' . $error->getMessage() . PHP_EOL);
                }
            }
            $attempted = $processed + $failed;
            fwrite(STDOUT, "Reprocessamento: {$attempted}/{$total} páginas. Falhas: {$failed}." . PHP_EOL);
        }
        fwrite(STDOUT, "Páginas reprocessadas: {$processed}. Falhas: {$failed}." . PHP_EOL);
        return $failed;
    }
}
