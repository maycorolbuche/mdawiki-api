<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedPages
{
    public function __construct(private Database $db, private Accounts $accounts, private WikiRenderer $renderer)
    {
    }

    private function page(string $slug, array $user, bool $lock = false, bool $allowMissing = false): ?array
    {
        $page = NormalizedPageRepository::lookup($this->db, $slug, $lock);
        if (!$page && $allowMissing) {
            return null;
        }
        if (!$page || str_starts_with($page['legacy_identifier'], 'page@')) {
            throw new HttpError(404, 'not_found', 'Página não encontrada.');
        }
        if ((int) ($page['access_level_id'] ?? 1) > (int) $user['NIVEL']) {
            throw new HttpError(403, 'permission_denied', 'Você não tem permissão para esta página.');
        }
        if ($page['kind'] === 'image') {
            throw new HttpError(501, 'image_editor_unavailable', 'O editor de imagens ainda está em migração.');
        }
        return $page;
    }

    private function version(?array $page): string
    {
        return $page === null ? '' : hash('sha256', serialize([$page['id'], $page['title'], $page['source'], $page['updated_at'], $page['updated_by']]));
    }

    public function editor(string $slug): array
    {
        $page = $this->page($slug, $this->accounts->current(true), allowMissing: true);
        if ($page === null) {
            [$canonical] = $this->newPageAddress($slug);
            return ['slug' => $canonical, 'title' => str_replace(['-', '_'], ' ', $canonical), 'source' => '',
                'base_version' => '', 'format' => 'wiki', 'exists' => false, 'media' => false];
        }
        return [
            'slug' => $page['slug'], 'title' => $page['title'], 'source' => $page['source'] ?? '',
            'base_version' => $this->version($page), 'format' => $page['is_html'] ? 'html' : 'wiki',
            'exists' => true, 'media' => false,
        ];
    }

    public function history(string $slug, int $before): array
    {
        $user = $this->accounts->current(true);
        $page = $this->page($slug, $user);
        $rows = $this->db->rows('SELECT r.revision_number AS id, r.title, COALESCE(r.source, \'\') AS source,
            r.created_at, u.display_name AS author, NULL AS author_photo
            FROM page_revisions r LEFT JOIN users u ON u.id = r.created_by
            WHERE r.page_id = ? AND r.revision_number < ? AND COALESCE(r.access_level_id, 1) <= ?
            AND COALESCE(r.is_expired, 0) = 0 ORDER BY r.revision_number DESC LIMIT 51',
            [$page['id'], $before, (int) $user['NIVEL']]);
        $revisions = array_slice($rows, 0, 50);
        return ['title' => $page['title'], 'revisions' => $revisions,
            'next_cursor' => count($rows) > 50 ? (int) end($revisions)['id'] : null,
            'base_version' => $this->version($page)];
    }

    public function preview(string $slug, array $body): array
    {
        $page = $this->page($slug, $this->accounts->current(true), allowMissing: true);
        $page ??= ['slug' => $this->newPageAddress($slug)[0], 'is_html' => false];
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
                (legacy_identifier, identifier, slug, kind, is_searchable, access_level_id, needs_rebuild, view_count,
                 is_html, created_by, created_at, legacy_checksum)
                VALUES (?, ?, ?, 'article', 1, 1, 0, 0, 0, ?, ?, ?)",
                ['@article:' . hash('sha256', $identifier), $identifier, $canonical, $user['ID'], $now, str_repeat('0', 64)]);
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            throw new HttpError(409, 'edit_conflict', 'Este endereço já foi criado. Recarregue a página antes de salvar.');
        }
        return $this->page($canonical, $user, true);
    }

    private function render(array $page, mixed $source): array
    {
        if (!is_string($source) || strlen($source) > 500000) {
            throw new HttpError(422, 'invalid_page', 'Conteúdo inválido (limite de 500 KB).');
        }
        return $this->renderer->render($source, $page['slug'], (bool) $page['is_html']);
    }

    public function save(string $slug, array $body, ?int $restore = null, bool $rebuild = false): array
    {
        $user = $this->accounts->current(true);
        if ($rebuild && (int) $user['NIVEL'] < 5) {
            throw new HttpError(403, 'permission_denied', 'Acesso restrito a administradores.');
        }
        return $this->write($slug, $body, $restore, $rebuild, $user);
    }

    public function rebuildCache(string $slug): array
    {
        return $this->write($slug, [], null, true, ['NIVEL' => PHP_INT_MAX]);
    }

    private function write(string $slug, array $body, ?int $restore, bool $rebuild, array $user): array
    {
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $creating = !$rebuild && $restore === null && ($body['base_version'] ?? null) === '';
            $page = $this->page($slug, $user, !$creating, $creating);
            if (!$rebuild && (!is_string($body['base_version'] ?? null)
                || !hash_equals($this->version($page), $body['base_version']))) {
                throw new HttpError(409, 'edit_conflict', 'Outra edição foi salva. Recarregue a página antes de salvar.');
            }
            if ($restore !== null) {
                $revision = $this->db->one('SELECT title, source FROM page_revisions WHERE page_id = ?
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
            $page ??= $this->insertPage($slug, $user, $now);
            $last = $this->db->one('SELECT MAX(revision_number) AS revision, LEAST(COALESCE(MIN(legacy_revision_number), 0), 0) AS legacy
                FROM page_revisions WHERE page_id = ?', [$page['id']]);
            $number = (int) $last['revision'];
            $rendered = $this->render($page, $source);
            if (!$rebuild && ($title !== $page['title'] || $source !== ($page['source'] ?? ''))) {
                $number++;
                $this->db->run('INSERT INTO page_revisions
                    (page_id, legacy_revision_number, revision_number, slug, title, source, rendered_html,
                     is_searchable, access_level_id, created_by, is_expired, created_at, legacy_checksum)
                     VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)',
                    [$page['id'], (int) $last['legacy'] - 1, $number, $page['slug'], $title, $source, $rendered['html'],
                     $page['is_searchable'], $page['access_level_id'], $user['ID'], $now, str_repeat('0', 64)]);
                $this->db->run('UPDATE pages SET title = ?, source = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                    [$title, $source, $now, $user['ID'], $page['id']]);
                $page['title'] = $title;
                $page['source'] = $source;
                $page['updated_at'] = $now;
                $page['updated_by'] = $user['ID'];
            }
            $this->db->run('UPDATE pages SET rendered_html = ?, plain_text = ?, rebuilt_at = ?, needs_rebuild = 0 WHERE id = ?',
                [$rendered['html'], $rendered['text'], $now, $page['id']]);
            (new PageRelations($this->db))->replace((int) $page['id'], $source, $rendered);
            if ($page['rendered_html'] !== $rendered['html']) {
                $this->db->run('UPDATE pages SET needs_rebuild = 1 WHERE id IN
                    (SELECT page_id FROM page_includes WHERE target_page_id = ?)', [$page['id']]);
            }
            $pdo->commit();
            return ['slug' => $page['slug'], 'revision' => $number, 'base_version' => $this->version($page)];
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}
