<?php
declare(strict_types=1);

namespace MDAWiki;

final class AdminPages
{
    private const PAGE_SIZE = 30;

    public function __construct(private Database $db)
    {
    }

    public function list(array $query): array
    {
        $search = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 250) : '';
        $page = max(1, (int) (filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $filters = [];
        $parameters = [];
        if ($search !== '') {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $filters[] = "(p.title LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern];
        }
        // Count the wiki source, including markup, so image-only articles have content.
        $size = "CHAR_LENGTH(TRIM(REPLACE(REPLACE(REPLACE(COALESCE(p.source, ''), CHAR(9), ' '), CHAR(10), ' '), CHAR(13), ' ')))";
        $content = $query['content'] ?? null;
        if ($content === 'yes') $filters[] = "$size > 0";
        if ($content === 'no') $filters[] = "$size = 0";
        $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
        $total = (int) $this->db->one('SELECT COUNT(*) AS total FROM pages p' . $where, $parameters)['total'];
        $page = min($page, max(1, (int) ceil($total / self::PAGE_SIZE)));
        $items = $this->db->rows("SELECT p.id, p.slug, COALESCE(NULLIF(p.title, ''), p.slug) AS title,
                $size AS characters
            FROM pages p" . $where . ' ORDER BY title, p.id LIMIT ? OFFSET ?',
            [...$parameters, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);
        $categories = [];
        if ($items) {
            $ids = array_column($items, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->rows("SELECT c.page_id, p.slug, COALESCE(NULLIF(p.title, ''), p.slug) AS title
                FROM page_categories c JOIN pages p ON p.id = c.category_id
                WHERE c.page_id IN ($placeholders) ORDER BY c.position, c.id", $ids) as $category) {
                $categories[$category['page_id']][] = ['slug' => $category['slug'], 'title' => $category['title']];
            }
        }
        foreach ($items as &$item) $item['categories'] = $categories[$item['id']] ?? [];
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => self::PAGE_SIZE];
    }

    public function remove(array $body): array
    {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) throw new HttpError(422, 'invalid_page', 'Página inválida.');

        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $page = $this->db->one('SELECT identifier FROM pages WHERE id = ? FOR UPDATE', [$id]);
            if (!$page) throw new HttpError(404, 'page_not_found', 'Página não encontrada.');
            $this->db->run('INSERT INTO page_missing_links (page_id, href, position, fragment, link_text)
                SELECT page_id, ?, position, fragment, link_text FROM page_links WHERE target_page_id = ? AND page_id <> ?',
                ['/' . $page['identifier'], $id, $id]);
            foreach (['page_includes' => 'target_page_id', 'page_redirects' => 'target_page_id'] as $table => $column) {
                $this->db->run("INSERT INTO page_rebuild_queue (page_id)
                    SELECT DISTINCT page_id FROM $table WHERE $column = ? AND page_id <> ?
                    ON DUPLICATE KEY UPDATE queued_at = CURRENT_TIMESTAMP", [$id, $id]);
                $this->db->run("DELETE FROM $table WHERE $column = ?", [$id]);
            }
            $this->db->run('DELETE FROM page_categories WHERE category_id = ?', [$id]);
            $this->db->run('DELETE FROM page_links WHERE target_page_id = ?', [$id]);
            foreach (['page_categories', 'page_external_links', 'page_headings', 'page_includes', 'page_links',
                'page_media', 'page_missing_links', 'page_rebuild_queue', 'page_redirects', 'page_revisions',
                'page_markup_index', 'page_templates', 'page_videos', 'page_visits'] as $table) {
                $this->db->run("DELETE FROM $table WHERE page_id = ?", [$id]);
            }
            $this->db->run('DELETE FROM pages WHERE id = ?', [$id]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['removed' => true];
    }
}
