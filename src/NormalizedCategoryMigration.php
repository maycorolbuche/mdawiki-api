<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedCategoryMigration
{
    public static function run(Database $db): int
    {
        // Check the target schema before making any changes.
        $db->rows('SELECT identifier, source FROM pages LIMIT 0');
        $db->rows('SELECT category_ids FROM page_revisions LIMIT 0');
        $db->rows('SELECT category_id FROM page_categories LIMIT 0');

        $relations = new PageRelations($db);
        $lastId = $db->one('SELECT COALESCE(MAX(id), 0) AS id FROM pages')['id'];
        $after = 0;
        $pages = $revisions = $failed = 0;
        while ($batch = $db->rows('SELECT id, slug FROM pages WHERE id > ? AND id <= ? ORDER BY id LIMIT 100', [$after, $lastId])) {
            foreach ($batch as $row) {
                $after = (int) $row['id'];
                $db->pdo->beginTransaction();
                try {
                    $page = $db->one('SELECT id, source FROM pages WHERE id = ? FOR UPDATE', [$after]);
                    $changedPage = $page ? self::migratePage($db, $relations, $page) : 0;
                    $changedRevisions = $page ? self::migrateRevisions($db, $relations, $after) : 0;
                    $db->pdo->commit();
                    $pages += $changedPage;
                    $revisions += $changedRevisions;
                } catch (\Throwable $error) {
                    if ($db->pdo->inTransaction()) {
                        $db->pdo->rollBack();
                    }
                    $failed++;
                    fwrite(STDERR, "Página {$row['slug']} (#{$after}): {$error->getMessage()}" . PHP_EOL);
                }
            }
            fwrite(STDOUT, "Até o ID {$after} de {$lastId}: {$pages} páginas e {$revisions} revisões migradas, {$failed} falhas." . PHP_EOL);
        }
        fwrite(STDOUT, "Total: {$pages} páginas e {$revisions} revisões migradas, {$failed} falhas." . PHP_EOL);
        return $failed;
    }

    private static function migratePage(Database $db, PageRelations $relations, array $page): int
    {
        $source = $page['source'] ?? '';
        $clean = WikiRenderer::withoutCategoryMarkup($source);
        if ($source === $clean) {
            return 0;
        }

        $pageId = (int) $page['id'];
        $current = array_map('strval', array_column($relations->categories($pageId), 'id'));
        $ids = self::categoryIds($relations, $source, $current);
        foreach (array_slice($ids, count($current)) as $id) {
            $db->run('INSERT INTO page_categories (page_id, category_id, position)
                SELECT ?, ?, COALESCE(MAX(position), 0) + 1 FROM page_categories WHERE page_id = ?', [$pageId, $id, $pageId]);
        }
        $db->run('UPDATE pages SET source = ? WHERE id = ?', [$clean, $pageId]);
        return 1;
    }

    private static function migrateRevisions(Database $db, PageRelations $relations, int $pageId): int
    {
        $after = $count = 0;
        while ($rows = $db->rows('SELECT id, revision_number, source, category_ids FROM page_revisions
            WHERE page_id = ? AND id > ? ORDER BY id LIMIT 100', [$pageId, $after])) {
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $source = $row['source'] ?? '';
                $clean = WikiRenderer::withoutCategoryMarkup($source);
                if ($source === $clean && $row['category_ids'] !== null) {
                    continue;
                }
                try {
                    $current = $row['category_ids'] === null ? []
                        : json_decode($row['category_ids'], true, flags: JSON_THROW_ON_ERROR);
                    $ids = self::categoryIds($relations, $source, $current);
                    $db->run('UPDATE page_revisions SET source = ?, category_ids = ? WHERE id = ?',
                        [$row['source'] === null ? null : $clean, json_encode($ids, JSON_THROW_ON_ERROR), $after]);
                    $count++;
                } catch (\Throwable $error) {
                    throw new \RuntimeException("Revisão {$row['revision_number']}: {$error->getMessage()}", 0, $error);
                }
            }
        }
        return $count;
    }

    private static function categoryIds(PageRelations $relations, string $source, array $current): array
    {
        $names = array_column(WikiRenderer::legacyCategories($source), 'title');
        return array_values(array_unique([...$current, ...$relations->categoryIds($names)]));
    }
}
