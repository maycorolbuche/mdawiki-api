<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedPageRepository implements PageRepository
{
    private const PUBLIC_PAGE = "p.is_searchable = 1 AND COALESCE(p.access_level_id, 1) <= ? AND CHAR_LENGTH(p.identifier) > 1 AND p.legacy_identifier NOT LIKE 'page@%'";
    private const SELECT_PAGE = 'SELECT p.id, p.slug, p.title, p.kind, p.rendered_html AS html,
        p.plain_text AS text, COALESCE(p.rebuilt_at, p.updated_at) AS updated_at
        FROM pages p';

    public function __construct(private readonly Database $db, private readonly int $maxLevel, private readonly string $mediaUrl, private readonly string $frontendUrl = 'https://mda.wiki.br')
    {
    }

    public function list(int $limit, string $after): array
    {
        if ($after !== '' && !preg_match('/^[1-9][0-9]{0,19}$/D', $after)) {
            throw new \InvalidArgumentException('Cursor inválido para a base migrada.');
        }
        $rows = $this->db->rows(self::SELECT_PAGE . ' WHERE ' . self::PUBLIC_PAGE
            . ' AND p.id > CAST(? AS UNSIGNED) ORDER BY p.id LIMIT ?', [$this->maxLevel, $after ?: '0', $limit]);

        return array_map($this->withRelations(...), $rows);
    }

    public static function lookup(Database $db, string $slug, bool $lock = false): ?array
    {
        try {
            [, $identifier] = PageSlugs::fromTitle($slug, '');
        } catch (\RuntimeException) {
            return null;
        }
        return $db->one('SELECT * FROM pages WHERE identifier = ? OR legacy_identifier = ?
            ORDER BY identifier = ? DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$identifier, Database::id($slug), $identifier]);
    }

    public function find(string $slug): ?array
    {
        try {
            [, $identifier] = PageSlugs::fromTitle($slug, '');
        } catch (\RuntimeException) {
            return null;
        }
        $row = $this->db->one(self::SELECT_PAGE . ' WHERE ' . self::PUBLIC_PAGE
            . ' AND (p.identifier = ? OR p.legacy_identifier = ?) ORDER BY p.identifier = ? DESC LIMIT 1',
            [$this->maxLevel, $identifier, Database::id($slug), $identifier]);
        if ($row === null) {
            return null;
        }
        return $this->withRelations($row);
    }

    private function withRelations(array $row): array
    {
        $page = $this->present($row);
        $page = array_replace($page, (new NormalizedArticleHtml($this->db, $this->maxLevel, $this->mediaUrl, $this->frontendUrl))
            ->decorate($page['html'] ?? ''));
        $page['mentions'] = $this->relationCards('page_links l JOIN pages p ON p.id = l.page_id',
            'l.target_page_id = ? AND p.id <> ?', [$page['id'], $page['id']]);
        $page['related_pages'] = $this->relationCards(
            'page_categories own JOIN page_categories related ON related.category_id = own.category_id JOIN pages p ON p.id = related.page_id',
            'own.page_id = ? AND p.id <> ?', [$page['id'], $page['id']], true);
        $categories = $this->db->rows('SELECT p.id, p.slug, p.title FROM page_categories c
            JOIN pages p ON p.id = c.category_id WHERE c.page_id = ? AND ' . self::PUBLIC_PAGE
            . ' ORDER BY c.position, c.id', [$page['id'], $this->maxLevel]);
        $page['categories'] = array_map(static fn (array $category): array => [
            'id' => (string) $category['id'], 'slug' => $category['slug'], 'title' => $category['title'] ?? $category['slug'],
        ], $categories);
        $members = $this->db->rows('SELECT p.id, p.slug, COALESCE(p.title, p.slug) AS title
            FROM page_categories c JOIN pages p ON p.id = c.page_id WHERE c.category_id = ? AND ' . self::PUBLIC_PAGE
            . ' ORDER BY p.title, p.id', [$page['id'], $this->maxLevel]);
        $page['category_members'] = array_map(static fn (array $member): array => [
            'id' => (string) $member['id'], 'slug' => $member['slug'], 'title' => $member['title'],
        ], $members);
        $redirect = $this->db->one('SELECT p.slug FROM page_redirects r JOIN pages p ON p.id = r.target_page_id
            WHERE r.page_id = ? AND ' . self::PUBLIC_PAGE . ' ORDER BY r.position, r.id LIMIT 1',
            [$page['id'], $this->maxLevel]);
        $page['redirect_slug'] = $redirect['slug'] ?? null;

        return $page;
    }

    private function relationCards(string $joins, string $where, array $params, bool $randomSample = false): array
    {
        $sql = "SELECT DISTINCT p.id, p.slug, COALESCE(p.title, p.slug) AS title FROM $joins
            WHERE $where AND " . self::PUBLIC_PAGE;
        $sql = $randomSample ? "SELECT * FROM ($sql) AS candidates ORDER BY RAND() LIMIT 5" : $sql . ' ORDER BY title, p.id';
        $rows = $this->db->rows($sql, [...$params, $this->maxLevel]);
        return array_map(static fn (array $row): array => ['id' => (string) $row['id']] + $row, $rows);
    }

    private const CARD = 'p.id, p.slug, COALESCE(p.title, p.slug) AS title,
        LEFT(p.plain_text, 240) AS description, COALESCE(p.updated_at, p.created_at) AS updated_at';

    public function search(array $query): array
    {
        $term = $query['q'] ?? '';
        $limit = filter_var($query['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if (!is_string($term) || mb_strlen($term) > 100 || $limit === false || $offset === false) {
            throw new HttpError(422, 'invalid_search', 'Busca ou paginação inválida.');
        }
        if (trim($term) === '') {
            return ['items' => [], 'next_offset' => null];
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)) . '%';
        $rows = $this->db->rows('SELECT ' . self::CARD . ' FROM pages p WHERE ' . self::PUBLIC_PAGE
            . " AND (p.title LIKE ? ESCAPE '!' OR p.plain_text LIKE ? ESCAPE '!') ORDER BY p.title, p.id LIMIT ? OFFSET ?",
            [$this->maxLevel, $like, $like, $limit + 1, $offset]);
        return ['items' => array_map($this->card(...), array_slice($rows, 0, $limit)),
            'next_offset' => count($rows) > $limit ? $offset + $limit : null];
    }

    public function statistics(): array
    {
        $row = $this->db->one("SELECT COUNT(*) AS pages, (SELECT COUNT(*) FROM images WHERE access_level_id <= ?) AS images,
            SUM(p.view_count) AS views FROM pages p WHERE " . self::PUBLIC_PAGE, [$this->maxLevel, $this->maxLevel]);
        return array_map('intval', $row);
    }

    public function recordVisit(string $slug, Session $session): bool
    {
        $page = self::lookup($this->db, $slug);
        if (!$page || !$this->db->one('SELECT p.id FROM pages p WHERE p.id = ? AND ' . self::PUBLIC_PAGE,
            [$page['id'], $this->maxLevel])) {
            throw new HttpError(404, 'not_found', 'Página não encontrada.');
        }
        $id = (int) $page['id'];
        $now = time();
        $recent = array_filter($session->data['visits'] ?? [], static fn ($time) => $time > $now - 30);
        if (isset($recent[$id])) return false;
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $this->db->run('INSERT INTO page_visits (page_id, visited_at) VALUES (?, ?)', [$id, $now]);
            $this->db->run('UPDATE pages SET view_count = COALESCE(view_count, 0) + 1 WHERE id = ?', [$id]);
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        $recent[$id] = $now;
        $session->data['visits'] = $recent;
        return true;
    }

    public function home(): array
    {
        $columns = self::CARD . ', (SELECT f.storage_key FROM page_media m
            JOIN images i ON i.id = m.image_id JOIN files f ON f.id = i.file_id
            WHERE m.page_id = p.id AND COALESCE(m.is_template, 0) = 0 AND i.access_level_id <= ?
            ORDER BY m.position, m.id LIMIT 1) AS image';
        $sql = 'SELECT ' . $columns . ' FROM pages p WHERE ' . self::PUBLIC_PAGE;
        $withContent = " AND p.source REGEXP '[^[:space:]]'";
        $levels = [$this->maxLevel, $this->maxLevel];
        $result = [
            'statistics' => $this->statistics(),
            'random' => array_map($this->card(...), $this->db->rows($sql . $withContent . ' ORDER BY RAND() LIMIT 3', $levels)),
            'recent' => array_map($this->card(...), $this->db->rows($sql . ' ORDER BY updated_at DESC, p.id LIMIT 10', $levels)),
            'popular' => array_map($this->card(...), $this->db->rows($sql . $withContent . ' ORDER BY p.view_count DESC, p.id LIMIT 10', $levels)),
            'missing' => [],
        ];
        $now = time();
        foreach (['top_24h' => 1, 'top_week' => 7, 'top_month' => 30] as $key => $days) {
            $rows = $this->db->rows('SELECT ' . $columns . ', v.views FROM pages p JOIN
                (SELECT page_id, COUNT(*) AS views FROM page_visits
                    WHERE visited_at >= ? AND visited_at <= ? GROUP BY page_id) v ON v.page_id = p.id
                WHERE ' . self::PUBLIC_PAGE . $withContent . ' ORDER BY v.views DESC, p.id LIMIT 10',
                [$this->maxLevel, $now - $days * 86400, $now, $this->maxLevel]);
            $result[$key] = array_map($this->card(...), $rows);
        }
        $result['top_today'] = $result['top_24h']; // Compatibility with older home clients.
        return $result;
    }

    private function card(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['description'] ??= '';
        $row['image'] = empty($row['image']) ? null : rtrim($this->mediaUrl, '/') . '/' . $row['image'];
        return $row;
    }

    public function canServeArticleFile(string $key): bool
    {
        return $this->db->one('SELECT f.id FROM files f JOIN images i ON i.access_level_id <= ?
            WHERE f.storage_key = ? AND (i.file_id = f.id OR EXISTS
                (SELECT 1 FROM image_revisions r WHERE r.image_id = i.id AND r.file_id = f.id AND r.access_level_id <= ?)) LIMIT 1',
            [$this->maxLevel, $key, $this->maxLevel]) !== null;
    }

    private function present(array $row): array
    {
        $row['id'] = (string) $row['id'];
        return $row;
    }
}
