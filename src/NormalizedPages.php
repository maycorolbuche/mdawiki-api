<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedPages
{
    public function __construct(private Database $db, private Accounts $accounts, private WikiRenderer $renderer, private ?WikiMail $notifications = null)
    {
    }

    private function page(string $slug, bool $lock = false, bool $allowMissing = false): ?array
    {
        $page = NormalizedPageRepository::lookup($this->db, $slug, $lock);
        if (!$page && $allowMissing) {
            return null;
        }
        if (!$page || $page['kind'] === 'system') {
            throw new HttpError(404, 'not_found', 'Página não encontrada.');
        }
        if ($page['kind'] === 'image') {
            throw new HttpError(501, 'image_editor_unavailable', 'O editor de imagens ainda está em migração.');
        }
        return $page;
    }

    private function version(?array $page): string
    {
        return $page === null ? '' : hash('sha256', serialize([$page['id'], $page['title'], $page['source'], $page['updated_at'], $page['updated_by'],
            array_column((new PageRelations($this->db))->categories((int) $page['id']), 'id')]));
    }

    private function editorAccount(): array
    {
        $user = $this->accounts->current(true);
        if (!in_array($user['role'] ?? null, ['user', 'admin'], true)) {
            throw new HttpError(403, 'permission_denied', 'Você não tem permissão para alterar páginas.');
        }
        return $user;
    }

    public function editor(string $slug): array
    {
        $this->editorAccount();
        $page = $this->page($slug, allowMissing: true);
        $options = $this->db->rows("SELECT p.slug, COALESCE(p.title, p.slug) AS title
            FROM pages p WHERE p.is_searchable = 1 AND p.kind NOT IN ('image', 'system')
            AND EXISTS (SELECT 1 FROM page_categories c WHERE c.category_id = p.id)
            ORDER BY title, p.id");
        if ($page === null) {
            [$canonical] = $this->newPageAddress($slug);
            return ['slug' => $canonical, 'title' => str_replace(['-', '_'], ' ', $canonical), 'source' => '',
                'base_version' => '', 'format' => 'wiki', 'exists' => false, 'media' => false,
                'categories' => [], 'category_options' => $options];
        }
        $source = $page['source'] ?? '';
        $categories = (new PageRelations($this->db))->categories((int) $page['id']);
        $configured = array_fill_keys(array_map(
            static fn (array $category) => PageSlugs::fromTitle($category['slug'], '')[1], $categories), true);
        foreach (WikiRenderer::legacyCategories($source) as $category) {
            try {
                [, $identifier] = PageSlugs::fromTitle($category['slug'], '');
            } catch (\RuntimeException) {
                continue;
            }
            if (!isset($configured[$identifier])) {
                $categories[] = $category;
                $configured[$identifier] = true;
            }
        }
        return [
            'slug' => $page['slug'], 'title' => $page['title'], 'source' => WikiRenderer::withoutCategoryMarkup($source),
            'categories' => $categories,
            'category_options' => $options,
            'base_version' => $this->version($page), 'format' => 'wiki',
            'exists' => true, 'media' => false,
        ];
    }

    public function history(string $slug, int $before): array
    {
        $user = $this->accounts->current(true);
        $page = $this->page($slug);
        $rows = $this->db->rows('SELECT r.revision_number AS id, r.title, COALESCE(r.source, \'\') AS source,
            r.category_ids, r.created_at, COALESCE(NULLIF(u.display_name, \'\'), u.name) AS author, u.avatar_filename AS FOTO, CASE WHEN u.is_active = 1 THEN u.public_id END AS author_profile_id
            FROM page_revisions r LEFT JOIN users u ON u.id = r.created_by
            WHERE r.page_id = ? AND r.revision_number < ? AND COALESCE(r.access_level_id, 1) <= ?
            AND COALESCE(r.is_expired, 0) = 0 ORDER BY r.revision_number DESC LIMIT 51',
            [$page['id'], $before, max(1, (int) $user['NIVEL'])]);
        $visibleRows = array_slice($rows, 0, 50);
        $categoryIds = [];
        foreach ($visibleRows as &$row) {
            $row['category_ids'] = $row['category_ids'] === null
                ? null : json_decode($row['category_ids'], true, flags: JSON_THROW_ON_ERROR);
            array_push($categoryIds, ...($row['category_ids'] ?? []));
        }
        unset($row);
        $categoryNames = [];
        foreach (array_chunk(array_values(array_unique($categoryIds)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $names = $this->db->rows("SELECT id, identifier AS `key`, COALESCE(title, slug) AS title
                FROM pages WHERE id IN ($placeholders)", $chunk);
            $categoryNames += array_column($names, null, 'id');
        }
        $revisions = array_map(function (array $row) use ($categoryNames): array {
            $row['author_photo'] = $this->accounts->profile($row)['photo'];
            $categories = [];
            if ($row['category_ids'] === null) {
                foreach (WikiRenderer::legacyCategories($row['source']) as $category) {
                    try {
                        [, $key] = PageSlugs::fromTitle($category['slug'], '');
                    } catch (\RuntimeException) {
                        $key = $category['id'];
                    }
                    $categories[$key] = ['key' => $key, 'title' => $category['title']];
                }
            } else {
                foreach ($row['category_ids'] as $id) {
                    $category = $categoryNames[$id] ?? ['key' => "deleted:$id", 'title' => "Categoria excluída (#$id)"];
                    $categories[$category['key']] = ['key' => $category['key'], 'title' => $category['title']];
                }
            }
            $row['categories'] = array_values($categories);
            $row['source'] = WikiRenderer::withoutCategoryMarkup($row['source']);
            unset($row['FOTO'], $row['category_ids']);
            return $row;
        }, $visibleRows);
        return ['title' => $page['title'], 'revisions' => $revisions,
            'next_cursor' => count($rows) > 50 ? (int) end($revisions)['id'] : null,
            'base_version' => $this->version($page)];
    }

    public function preview(string $slug, array $body): array
    {
        $this->editorAccount();
        $page = $this->page($slug, allowMissing: true);
        $page ??= ['slug' => $this->newPageAddress($slug)[0]];
        return ['html' => $this->render($page, $body['source'] ?? null)['html']];
    }

    private function newPageAddress(string $slug): array
    {
        if (str_contains($slug, '@')) {
            throw new HttpError(422, 'invalid_slug', 'Este endereço é reservado.');
        }
        try {
            [$canonical, $identifier] = PageSlugs::fromTitle($slug, '');
        } catch (\RuntimeException) {
            throw new HttpError(422, 'invalid_slug', 'Endereço de página inválido.');
        }
        if (mb_strlen($identifier) < 2 || mb_strlen($canonical) > 250 || mb_strlen($identifier) > 250) {
            throw new HttpError(422, 'invalid_slug', 'Endereço de página inválido.');
        }
        return [$canonical, $identifier];
    }

    private function insertPage(string $slug, array $user, string $now): array
    {
        [$canonical, $identifier] = $this->newPageAddress($slug);
        try {
            $this->db->run("INSERT INTO pages
                (identifier, slug, kind, is_searchable, view_count, created_by, created_at)
                VALUES (?, ?, 'article', 1, 0, ?, ?)",
                [$identifier, $canonical, $user['ID'], $now]);
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            throw new HttpError(409, 'edit_conflict', 'Este endereço já foi criado. Recarregue a página antes de salvar.');
        }
        return $this->page($canonical, true);
    }

    private function render(array $page, mixed $source): array
    {
        $this->validateSource($source);
        return $this->renderer->render($source, $page['slug'], false, isset($page['id']) ? (int) $page['id'] : null);
    }

    private function validateSource(mixed $source): void
    {
        if (!is_string($source) || strlen($source) > 500000 || !mb_check_encoding($source, 'UTF-8')) {
            throw new HttpError(422, 'invalid_page', 'Conteúdo inválido (limite de 500 KB).');
        }
    }

    public function save(string $slug, array $body, ?int $restore = null, bool $rebuild = false): array
    {
        $user = $this->editorAccount();
        if ($rebuild && ($user['role'] ?? null) !== 'admin') {
            throw new HttpError(403, 'permission_denied', 'Acesso restrito a administradores.');
        }
        return $this->write($slug, $body, $restore, $rebuild, $user);
    }

    public function rebuildCache(string $slug, bool $migrateCategories = false): array
    {
        return $this->write($slug, [], null, true, ['NIVEL' => PHP_INT_MAX], $migrateCategories);
    }

    private function write(string $slug, array $body, ?int $restore, bool $rebuild, array $user, bool $migrateCategories = false): array
    {
        $pdo = $this->db->pdo;
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $creating = !$rebuild && $restore === null && ($body['base_version'] ?? null) === '';
            $page = $this->page($slug, !$creating, $creating);
            if (!$rebuild && (!is_string($body['base_version'] ?? null)
                || !hash_equals($this->version($page), $body['base_version']))) {
                throw new HttpError(409, 'edit_conflict', 'Outra edição foi salva. Recarregue a página antes de salvar.');
            }
            if ($restore !== null) {
                $revision = $this->db->one('SELECT title, COALESCE(source, \'\') AS source, category_ids FROM page_revisions WHERE page_id = ?
                    AND revision_number = ? AND COALESCE(access_level_id, 1) <= ? AND COALESCE(is_expired, 0) = 0',
                    [$page['id'], $restore, (int) $user['NIVEL']]);
                if (!$revision) {
                    throw new HttpError(404, 'not_found', 'Revisão não encontrada.');
                }
                $body = $revision;
            }
            $title = $rebuild ? $page['title'] : trim(is_string($body['title'] ?? null) ? $body['title'] : '');
            $source = $rebuild ? ($page['source'] ?? '') : ($body['source'] ?? null);
            if ($title === '' || mb_strlen($title ?? '') > 250) {
                throw new HttpError(422, 'invalid_page', 'Título inválido.');
            }
            $now = date('Y-m-d H:i:s');
            $new = $page === null;
            $page ??= $this->insertPage($slug, $user, $now);
            $last = $this->db->one('SELECT MAX(revision_number) AS revision
                FROM page_revisions WHERE page_id = ?', [$page['id']]);
            $number = (int) $last['revision'];
            $this->db->run('DELETE FROM page_rebuild_queue WHERE page_id = ?', [$page['id']]);
            $relations = new PageRelations($this->db);
            $currentIds = array_map('strval', array_column($relations->categories((int) $page['id']), 'id'));
            $this->validateSource($source);
            $legacyCategories = WikiRenderer::legacyCategories($source);
            $categoryIds = $currentIds;
            if ($restore !== null && $body['category_ids'] !== null) {
                $categoryIds = [];
                foreach (json_decode($body['category_ids'], true, flags: JSON_THROW_ON_ERROR) as $id) {
                    if ($this->db->one('SELECT id FROM pages WHERE id = ?', [$id])) {
                        $categoryIds[] = (string) $id;
                    }
                }
            } elseif (array_key_exists('categories', $body)) {
                $names = $body['categories'];
                if (!is_array($names) || !array_is_list($names)) {
                    throw new HttpError(422, 'invalid_category', 'Informe uma lista de categorias.');
                }
                foreach ($names as &$name) {
                    if (!is_string($name) || trim($name) === '' || !mb_check_encoding($name, 'UTF-8')
                        || mb_strlen($name) > 250 || preg_match('~[@/\x00-\x1f\x7f]~u', $name)) {
                        throw new HttpError(422, 'invalid_category', 'Informe um nome de categoria válido.');
                    }
                    $name = trim($name);
                }
                unset($name);
                $categoryIds = $relations->categoryIds($names);
            } elseif ($restore !== null || $legacyCategories !== []) {
                $categoryIds = $relations->categoryIds(array_column($legacyCategories, 'title'));
                if ($migrateCategories) {
                    $categoryIds = array_values(array_unique([...$currentIds, ...$categoryIds]));
                }
            }
            if ($migrateCategories || $restore !== null || array_key_exists('categories', $body)) {
                $source = WikiRenderer::withoutCategoryMarkup($source);
            }
            if ($migrateCategories && $source !== ($page['source'] ?? '')) {
                $this->db->run('UPDATE pages SET source = ? WHERE id = ?', [$source, $page['id']]);
                $page['source'] = $source;
            }
            $rendered = $this->render($page, $source);
            $edited = !$rebuild && ($new || $title !== $page['title'] || $source !== ($page['source'] ?? '')
                || $categoryIds !== $currentIds);
            if ($edited) {
                $number++;
                $this->db->run('INSERT INTO page_revisions
                    (page_id, revision_number, slug, title, source, rendered_html, category_ids,
                     is_searchable, access_level_id, created_by, is_expired, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,0,?)',
                    [$page['id'], $number, $page['slug'], $title, $source, $rendered['html'], json_encode($categoryIds, JSON_THROW_ON_ERROR),
                     $page['is_searchable'], 1, $user['ID'], $now]);
                $this->db->run('UPDATE pages SET title = ?, source = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                    [$title, $source, $now, $user['ID'], $page['id']]);
                $page['title'] = $title;
                $page['source'] = $source;
                $page['updated_at'] = $now;
                $page['updated_by'] = $user['ID'];
            }
            $this->db->run('UPDATE pages SET rendered_html = ?, plain_text = ?, rebuilt_at = ? WHERE id = ?',
                [$rendered['html'], $rendered['text'], $now, $page['id']]);
            if ($new) $relations->resolveMissing((int) $page['id'], $page['identifier']);
            $relations->replace((int) $page['id'], $source, $rendered, $categoryIds);
            if ($page['rendered_html'] !== $rendered['html']) {
                $this->db->run('INSERT INTO page_rebuild_queue (page_id)
                    SELECT DISTINCT page_id FROM page_includes WHERE target_page_id = ? AND page_id <> ?
                    ON DUPLICATE KEY UPDATE queued_at = CURRENT_TIMESTAMP', [$page['id'], $page['id']]);
            }
            if ($ownsTransaction) $pdo->commit();
            if ($ownsTransaction && $edited) {
                $this->notifications?->event('page', $new ? 'new' : 'update', $title, $user,
                    ['Endereço' => $page['slug'], 'Conteúdo' => $source], path: '/' . rawurlencode($page['slug']));
            }
            return ['slug' => $page['slug'], 'revision' => $number, 'base_version' => $this->version($page)];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
