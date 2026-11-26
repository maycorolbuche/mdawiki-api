<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedImages
{
    public function __construct(private Database $db, private array $config, private ?NormalizedPages $pages = null, private ?WikiMail $notifications = null)
    {
    }

    private static function properties(string $key, string $bytes, array|false $dimensions): array
    {
        return ['extension' => pathinfo($key, PATHINFO_EXTENSION), 'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null, 'size_bytes' => strlen($bytes)];
    }

    public function lookup(string $name, bool $lock = false): ?array
    {
        try {
            [, $identifier] = PageSlugs::fromTitle(preg_replace('/^imagem@/iu', '', $name), '');
        } catch (\RuntimeException) {
            return null;
        }
        return $this->db->one('SELECT i.*, f.storage_key FROM images i LEFT JOIN files f ON f.id = i.file_id
            WHERE i.identifier = ? OR i.legacy_identifier = ? ORDER BY i.identifier = ? DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$identifier, 'imagem@' . Database::id(preg_replace('/^imagem@/iu', '', $name)), $identifier]);
    }

    public function show(string $name): array
    {
        $image = $this->lookup($name);
        if (!$image || (int) $image['access_level_id'] > (int) $this->config['public_max_level']) {
            throw new HttpError(404, 'image_not_found', 'Imagem não encontrada.');
        }
        $members = $this->db->rows("SELECT DISTINCT p.id, p.slug, COALESCE(p.title, p.slug) AS title
            FROM page_media m JOIN pages p ON p.id = m.page_id
            WHERE m.image_id = ? AND p.is_searchable = 1
            AND p.kind <> 'system' ORDER BY title, p.id", [$image['id']]);
        $history = $this->db->rows("SELECT r.id, r.created_at, f.storage_key,
            COALESCE(NULLIF(u.display_name, ''), NULLIF(u.name, '')) AS author, CASE WHEN u.is_active = 1 THEN u.public_id END AS author_profile_id FROM image_revisions r
            JOIN files f ON f.id = r.file_id LEFT JOIN users u ON u.id = r.created_by
            WHERE r.image_id = ? AND r.access_level_id <= ?
            ORDER BY r.created_at DESC, r.id DESC", [$image['id'], $this->config['public_max_level']]);
        return ['slug' => $image['slug'], 'title' => $image['title'],
            'url' => $this->url($image['storage_key']), 'meta' => json_decode($image['meta'], true, 512, JSON_THROW_ON_ERROR),
            'version' => (string) ($image['file_id'] ?? ''), 'pages' => $members,
            'history' => array_map(fn (array $row): array => ['id' => $row['id'], 'author' => $row['author'], 'author_profile_id' => $row['author_profile_id'], 'created_at' => $row['created_at'],
                'url' => $this->url($row['storage_key'])], $history)];
    }

    private function url(?string $key): ?string
    {
        return $key === null ? null : $this->config['media_public_url'] . '/' . $key;
    }

    public function save(string $name, array $body, array $user): array
    {
        try {
            [$slug, $identifier] = PageSlugs::fromTitle($name, '');
        } catch (\RuntimeException) {
            throw new HttpError(422, 'invalid_image_name', 'Informe um nome de imagem válido.');
        }
        if (str_contains($name, '@') || mb_strlen($identifier) > 250 || mb_strlen($slug) > 250) {
            throw new HttpError(422, 'invalid_image_name', 'Informe um nome de imagem válido.');
        }
        $data = $body['data'] ?? null;
        $bytes = is_string($data) && preg_match('~^data:image/(?:png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$~D', $data, $match)
            ? base64_decode($match[1], true) : false;
        $size = $bytes !== false && strlen($bytes) <= 5000000 ? @getimagesizefromstring($bytes) : false;
        $extension = $size ? (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$size['mime']] ?? null) : null;
        if ($extension === null || $size[0] * $size[1] > 20000000) {
            throw new HttpError(422, 'invalid_upload', 'Envie uma imagem PNG, JPEG, GIF ou WebP de até 5 MB e 20 megapixels.');
        }
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $image = $this->lookup($name);
            if ($image) {
                $image = $this->lookup($name, true);
            }
            $level = (int) ($image['access_level_id'] ?? $this->config['public_max_level']);
            if ($level > (int) $user['NIVEL'] || $level > (int) $this->config['public_max_level']) {
                throw new HttpError(403, 'permission_denied', 'Você não pode alterar esta imagem.');
            }
            if (($body['base_version'] ?? null) !== (string) ($image['file_id'] ?? '')) {
                throw new HttpError(409, 'edit_conflict', 'A imagem mudou. Atualize a página antes de enviar.');
            }
            $hash = hash('sha256', $bytes);
            $key = 'articles/' . $hash . '.' . $extension;
            (new MediaStorage($this->config))->write($key, $bytes);
            $this->db->run('INSERT INTO files (sha256, storage_key, mime_type, size_bytes) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = id', [$hash, $key, $size['mime'], strlen($bytes)]);
            $file = $this->db->one('SELECT id FROM files WHERE storage_key = ? FOR UPDATE', [$key])['id'];
            $meta = json_encode(self::properties($key, $bytes, $size), JSON_THROW_ON_ERROR);
            if (!$image) {
                $this->db->run('INSERT INTO images (identifier, slug, title, meta, file_id, access_level_id, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())',
                    [$identifier, $slug, str_replace(['-', '_'], ' ', $slug), $meta, $file, $level, $user['ID'], $user['ID']]);
                $this->recordRevision($pdo->lastInsertId(), $file, $level, $user['ID']);
                $this->rebuildWaitingPages($identifier);
            } elseif ((string) $image['file_id'] !== (string) $file) {
                $this->replace($image, $file, $meta, (int) $user['ID']);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof \PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError(409, 'edit_conflict', 'A imagem já existe. Atualize a página antes de enviar.');
            }
            throw $error;
        }
        if (!$image || (string) $image['file_id'] !== (string) $file) {
            $this->notify($image && $image['file_id'] !== null ? 'update' : 'new', $user,
                $image['title'] ?? str_replace(['-', '_'], ' ', $slug), $image['slug'] ?? $slug, $key, $meta);
        }
        return $this->show($image['slug'] ?? $slug);
    }
    public function clear(string $name, array $body, array $user): array
    {
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $image = $this->lookup($name, true);
            $level = min((int) $user['NIVEL'], (int) $this->config['public_max_level']);
            if (!$image || (int) $image['access_level_id'] > $level) {
                throw new HttpError(404, 'image_not_found', 'Imagem não encontrada.');
            }
            if (($body['base_version'] ?? null) !== (string) ($image['file_id'] ?? '')) {
                throw new HttpError(409, 'edit_conflict', 'A imagem mudou. Atualize a página antes de excluir.');
            }
            if ($image['file_id'] !== null) {
                $this->replace($image, null, '{}', (int) $user['ID']);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        if ($image['file_id'] !== null) {
            $this->notify('delete', $user, $image['title'] ?? $image['slug'], $image['slug'], $image['storage_key'], $image['meta']);
        }
        return $this->show($image['slug']);
    }

    public function restore(string $name, array $body, array $user): array
    {
        $revisionId = filter_var($body['revision_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revisionId === false) throw new HttpError(422, 'invalid_revision', 'Informe uma revisão válida.');
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $image = $this->lookup($name, true);
            $level = min((int) $user['NIVEL'], (int) $this->config['public_max_level']);
            if (!$image || (int) $image['access_level_id'] > $level) {
                throw new HttpError(404, 'image_not_found', 'Imagem não encontrada.');
            }
            if (($body['base_version'] ?? null) !== (string) ($image['file_id'] ?? '')) {
                throw new HttpError(409, 'edit_conflict', 'A imagem mudou. Atualize a página antes de restaurar.');
            }
            $revision = $this->db->one('SELECT r.file_id, f.storage_key FROM image_revisions r JOIN files f ON r.file_id = f.id
                WHERE r.image_id = ? AND r.id = ? AND r.access_level_id <= ?', [$image['id'], $revisionId, $level]);
            $bytes = $revision ? (new MediaStorage($this->config))->read($revision['storage_key']) : null;
            if ($bytes === null) {
                throw new HttpError(404, 'revision_not_found', 'O arquivo desta revisão não está disponível.');
            }
            if ((string) $image['file_id'] !== (string) $revision['file_id']) {
                $meta = json_encode(self::properties($revision['storage_key'], $bytes, @getimagesizefromstring($bytes)), JSON_THROW_ON_ERROR);
                $this->replace($image, $revision['file_id'], $meta, (int) $user['ID']);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        if (isset($meta)) {
            $this->notify($image['file_id'] === null ? 'new' : 'update', $user, $image['title'] ?? $image['slug'],
                $image['slug'], $revision['storage_key'], $meta);
        }
        return $this->show($image['slug']);
    }

    private function notify(string $action, array $user, string $title, string $slug, ?string $key, string $meta): void
    {
        $properties = json_decode($meta, true) ?? [];
        $url = $this->url($key);
        $this->notifications?->event('img', $action, $title, $user, [
            'Endereço' => $slug, 'Arquivo' => $url, 'Formato' => $properties['extension'] ?? null,
            'Largura' => $properties['width'] ?? null, 'Altura' => $properties['height'] ?? null,
            'Tamanho em bytes' => $properties['size_bytes'] ?? null,
        ], $url, '/i/' . rawurlencode($slug));
    }

    /** Rebuilds articles that already stored this image template before its upload. */
    private function rebuildWaitingPages(string $identifier): void
    {
        if ($this->pages === null) {
            throw new \LogicException('O reprocessador de páginas é obrigatório para criar imagens.');
        }
        require_once __DIR__ . '/wiki-presentation.php';
        $templates = $this->db->rows("SELECT p.id, p.slug, t.template_source, t.template_key
            FROM page_templates t JOIN pages p ON p.id = t.page_id
            WHERE t.template_key LIKE 'imagem:%' AND p.kind <> 'image'
            AND p.kind <> 'system' ORDER BY p.id, t.position");
        $waiting = [];
        foreach ($templates as $template) {
            if (isset($waiting[$template['id']])) continue;
            $body = preg_match('~^\{\{Imagem:(.*?)\}\}$~isD', $template['template_source'] ?? '', $match)
                ? $match[1] : substr($template['template_key'], strlen('imagem:'));
            $options = \MDAWiki\Wiki\image_template_options($body);
            $name = preg_replace('/!tmpl$/', '', \MDAWiki\Wiki\str_to_url($options['url'] ?? '', false));
            try {
                [, $target] = PageSlugs::fromTitle($name, '');
            } catch (\RuntimeException) {
                continue;
            }
            if ($target === $identifier) $waiting[$template['id']] = $template['slug'];
        }
        foreach ($waiting as $slug) {
            $this->pages->rebuildCache($slug);
        }
    }

    private function recordRevision(int|string $image, int|string $file, int $level, int|string|null $author, ?string $date = null): void
    {
        $this->db->run('INSERT INTO image_revisions (image_id, file_id, created_at, access_level_id, created_by)
            VALUES (?, ?, COALESCE(?, CURRENT_TIMESTAMP), ?, ?)', [$image, $file, $date, $level, $author]);
    }

    private function replace(array $image, int|string|null $file, string $meta, int $author): void
    {
        // Older images may still have a current file that was never recorded in history.
        $latest = $this->db->one('SELECT file_id FROM image_revisions WHERE image_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$image['id']]);
        $level = (int) $image['access_level_id'];
        if ($image['file_id'] !== null && (string) $image['file_id'] !== (string) ($latest['file_id'] ?? '')) {
            $this->recordRevision($image['id'], $image['file_id'], $level,
                $image['updated_by'] ?? $image['created_by'], $image['updated_at'] ?? $image['created_at']);
        }
        $this->db->run('UPDATE images SET file_id = ?, meta = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$file, $meta, $author, $image['id']]);
        if ($file !== null) $this->recordRevision($image['id'], $file, $level, $author);
        if ($this->pages === null) {
            throw new \LogicException('O reprocessador de páginas é obrigatório para substituir imagens.');
        }
        $linked = $this->db->rows("SELECT p.slug FROM pages p
            WHERE p.kind <> 'system' AND p.kind <> 'image'
            AND EXISTS (SELECT 1 FROM page_media m WHERE m.page_id = p.id AND m.image_id = ?)
            ORDER BY p.id", [$image['id']]);
        foreach ($linked as $page) {
            $this->pages->rebuildCache($page['slug']);
        }
    }

}
