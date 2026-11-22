<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedPageRepository implements PageRepository
{
    private const PUBLIC_PAGE = "p.is_searchable = 1 AND COALESCE(p.access_level_id, 1) <= ? AND p.legacy_identifier NOT LIKE 'page@%'";
    private const SELECT_PAGE = 'SELECT p.id, p.slug, p.title, p.kind, p.rendered_html AS html,
        p.plain_text AS text, COALESCE(p.rebuilt_at, p.updated_at) AS updated_at, f.storage_key
        FROM pages p LEFT JOIN images i ON i.page_id = p.id LEFT JOIN files f ON f.id = i.file_id';

    public function __construct(private readonly Database $db, private readonly int $maxLevel, private readonly string $mediaUrl)
    {
    }

    public function list(int $limit, string $after): array
    {
        if ($after !== '' && !preg_match('/^[1-9][0-9]{0,19}$/D', $after)) {
            throw new \InvalidArgumentException('Cursor inválido para a base migrada.');
        }
        $rows = $this->db->rows(self::SELECT_PAGE . ' WHERE ' . self::PUBLIC_PAGE
            . ' AND p.id > CAST(? AS UNSIGNED) ORDER BY p.id LIMIT ?', [$this->maxLevel, $after ?: '0', $limit]);

        return array_map($this->present(...), $rows);
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
        $page = $this->present($row);
        $categories = $this->db->rows('SELECT p.id, p.slug, p.title FROM page_categories c
            JOIN pages p ON p.id = c.category_id WHERE c.page_id = ? AND ' . self::PUBLIC_PAGE
            . ' ORDER BY c.position, c.id', [$page['id'], $this->maxLevel]);
        $page['categories'] = array_map(static fn (array $category): array => [
            'id' => (string) $category['id'], 'slug' => $category['slug'], 'title' => $category['title'] ?? $category['slug'],
        ], $categories);
        $redirect = $this->db->one('SELECT p.slug FROM page_redirects r JOIN pages p ON p.id = r.target_page_id
            WHERE r.page_id = ? AND ' . self::PUBLIC_PAGE . ' ORDER BY r.position, r.id LIMIT 1',
            [$page['id'], $this->maxLevel]);
        $page['redirect_slug'] = $redirect['slug'] ?? null;

        return $page;
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
        $row = $this->db->one("SELECT SUM(p.kind <> 'image') AS pages, SUM(p.kind = 'image') AS images,
            SUM(p.view_count) AS views FROM pages p WHERE " . self::PUBLIC_PAGE, [$this->maxLevel]);
        return array_map('intval', $row);
    }

    public function home(): array
    {
        $sql = 'SELECT ' . self::CARD . ' FROM pages p WHERE ' . self::PUBLIC_PAGE;
        return [
            'statistics' => $this->statistics(),
            'random' => array_map($this->card(...), $this->db->rows($sql . ' ORDER BY RAND() LIMIT 3', [$this->maxLevel])),
            'recent' => array_map($this->card(...), $this->db->rows($sql . ' ORDER BY updated_at DESC, p.id LIMIT 10', [$this->maxLevel])),
            'popular' => array_map($this->card(...), $this->db->rows($sql . ' ORDER BY p.view_count DESC, p.id LIMIT 10', [$this->maxLevel])),
            'top_today' => [], 'top_week' => [], 'top_month' => [], 'missing' => [],
        ];
    }

    private function card(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['description'] ??= '';
        $row['image'] = null;
        return $row;
    }

    public function canServeArticleFile(string $key): bool
    {
        return $this->db->one('SELECT p.id FROM files f JOIN images i ON i.file_id = f.id
            JOIN pages p ON p.id = i.page_id WHERE f.storage_key = ? AND ' . self::PUBLIC_PAGE . ' LIMIT 1',
            [$key, $this->maxLevel]) !== null;
    }

    private function present(array $row): array
    {
        $row['id'] = (string) $row['id'];
        if ($row['kind'] === 'image') {
            $file = $row['storage_key'] ?? '';
            $row['html'] = preg_match('~\Aarticles/[a-f0-9]{64}\.(?:avif|gif|jpe?g|png|svg|webp)\z~D', $file)
                ? '<figure><img src="' . htmlspecialchars($this->mediaUrl . '/' . $file, ENT_QUOTES, 'UTF-8')
                    . '" alt="' . htmlspecialchars($row['title'] ?? $row['slug'], ENT_QUOTES, 'UTF-8') . '"></figure>'
                : '<p>Esta página está sem imagem.</p>';
        }

        return $row;
    }
}
