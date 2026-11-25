<?php
declare(strict_types=1);

namespace MDAWiki;

final class AdminFiles
{
    private MediaStorage $storage;

    public function __construct(private Database $db, array $config, private NormalizedPages $pages)
    {
        $this->storage = new MediaStorage($config);
    }

    private const FILES = 'SELECT f.id, f.storage_key AS media_key, f.size_bytes,
        (SELECT COUNT(*) FROM image_revisions r WHERE r.file_id = f.id) AS revisions,
        (SELECT COUNT(*) FROM images i WHERE i.file_id = f.id) AS images,
        (SELECT COUNT(DISTINCT m.page_id) FROM page_media m JOIN images i ON i.id = m.image_id
            WHERE i.file_id = f.id) AS pages FROM files f';

    public function audit(): array
    {
        $inventory = $this->storage->inventory();
        $result = ['unlinked' => [], 'orphan' => [], 'missing' => [], 'locations' => array_keys($inventory)];
        $known = [];
        $avatars = [];
        foreach ($this->db->rows("SELECT id, avatar_filename FROM users WHERE avatar_filename IS NOT NULL AND avatar_filename <> ''") as $user) {
            $key = 'users/' . $user['avatar_filename'];
            $known[$key] = true;
            $avatars[$key] = true;
            if (MediaStorage::managedKey($key) && !$this->findIn($inventory, $key)) {
                $result['missing'][] = ['kind' => 'avatar', 'id' => $user['id'], 'media_key' => $key];
            }
        }
        foreach ($this->db->rows(self::FILES . ' ORDER BY f.id') as $file) {
            $key = $file['media_key'];
            $known[$key] = true;
            if (!MediaStorage::managedKey($key)) continue;
            $locations = $this->findIn($inventory, $key);
            $row = $file + ['kind' => 'file', 'locations' => $locations, 'avatar_in_use' => isset($avatars[$key])];
            if (!$locations) $result['missing'][] = $row;
            elseif (!(int) $file['pages']) $result['unlinked'][] = $row;
        }
        foreach ($inventory as $location => $objects) {
            foreach ($objects as $key => $size) {
                if (!isset($known[$key])) {
                    $result['orphan'][] = ['kind' => 'object', 'media_key' => $key, 'location' => $location, 'size_bytes' => $size];
                }
            }
        }
        return $result;
    }

    public function preview(array $query): array
    {
        $key = $query['media_key'] ?? null;
        $location = $query['location'] ?? null;
        if (!is_string($key) || !MediaStorage::managedKey($key)
            || !in_array($location, [null, 'local', 'r2'], true)) {
            throw new HttpError(422, 'invalid_media', 'Selecione um arquivo válido.');
        }
        $bytes = $this->storage->readAt($key, $location);
        if ($bytes === null) throw new HttpError(404, 'media_not_found', 'Arquivo ausente.');
        if (strlen($bytes) > 5000000) throw new HttpError(422, 'media_too_large', 'Arquivo grande demais para pré-visualização.');
        return ['image' => 'data:' . MediaStorage::contentType($key) . ';base64,' . base64_encode($bytes)];
    }

    private function findIn(array $inventory, string $key): array
    {
        return array_keys(array_filter($inventory, static fn (array $objects): bool => isset($objects[$key])));
    }

    public function remove(array $body): array
    {
        $action = $body['action'] ?? null;
        $key = $body['media_key'] ?? null;
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($key) || !MediaStorage::managedKey($key)
            || !in_array($action, ['unlinked', 'orphan', 'missing_file', 'missing_avatar', 'avatar_record'], true)
            || ($action !== 'orphan' && $id === false)) {
            throw new HttpError(422, 'invalid_media', 'Selecione um arquivo válido.');
        }
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $file = $this->db->one('SELECT id FROM files WHERE storage_key = ?' . $lock, [$key]);
            $avatar = str_starts_with($key, 'users/') ? substr($key, 6) : null;
            $users = $avatar === null ? [] : $this->db->rows('SELECT id FROM users WHERE avatar_filename = ?' . $lock, [$avatar]);
            $locations = $this->storage->locations($key);
            if ($action === 'orphan') {
                $location = $body['location'] ?? null;
                if ($file || $users || !in_array($location, $locations, true) || $this->directReference($key)) {
                    $this->conflict();
                }
                $this->storage->deleteAt($key, $location);
            } elseif ($action === 'avatar_record') {
                if (!$file || (string) $file['id'] !== (string) $id || !$users || !$locations
                    || $this->db->one('SELECT id FROM images WHERE file_id = ?' . $lock, [$id])
                    || $this->db->one('SELECT id FROM image_revisions WHERE file_id = ?' . $lock, [$id])) {
                    $this->conflict();
                }
                $this->db->run('DELETE FROM files WHERE id = ?', [$id]);
            } elseif ($action === 'missing_avatar') {
                if ($locations || !in_array((string) $id, array_map('strval', array_column($users, 'id')), true)) $this->conflict();
                $this->db->run('UPDATE users SET avatar_filename = NULL WHERE id = ? AND avatar_filename = ?', [$id, $avatar]);
            } else {
                if (!$file || (string) $file['id'] !== (string) $id
                    || ($action === 'missing_file' && $locations)
                    || ($action === 'unlinked' && (!$locations || $users || $this->directReference($key)))) {
                    $this->conflict();
                }
                $images = $this->db->rows('SELECT id FROM images WHERE file_id = ?' . $lock, [$id]);
                $linked = [];
                foreach ($images as $image) {
                    $references = $this->db->rows('SELECT page_id FROM page_media WHERE image_id = ?' . $lock, [$image['id']]);
                    if ($references && $action === 'unlinked') $this->conflict();
                    foreach ($references as $reference) $linked[$reference['page_id']] = true;
                }
                if ($action === 'missing_file') {
                    foreach ($this->db->rows('SELECT id FROM pages WHERE INSTR(rendered_html, ?) > 0', [$key]) as $page) {
                        $linked[$page['id']] = true;
                    }
                }
                $this->db->run("UPDATE images SET file_id = NULL, meta = '{}' WHERE file_id = ?", [$id]);
                foreach (array_keys($linked) as $pageId) {
                    $page = $this->db->one("SELECT slug FROM pages WHERE id = ? AND kind <> 'image'
                        AND legacy_identifier NOT LIKE 'page@%'", [$pageId]);
                    if ($page) $this->pages->rebuildCache($page['slug']);
                }
                $this->db->run('DELETE FROM image_revisions WHERE file_id = ?', [$id]);
                $this->db->run('DELETE FROM files WHERE id = ?', [$id]);
                foreach ($locations as $location) $this->storage->deleteAt($key, $location);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['removed' => true];
    }

    private function directReference(string $key): bool
    {
        $patterns = [$key];
        [$group, $filename] = explode('/', $key, 2);
        $legacy = ['wiki' => '@imgs_wiki/', 'wiki_mini' => '@imgs_wiki_mini/', 'wiki_cp' => '@imgs_wiki_cp/', 'users' => '@imgs_user/'];
        if (isset($legacy[$group])) $patterns[] = $legacy[$group] . $filename;
        foreach ($patterns as $pattern) {
            foreach (['pages', 'page_revisions'] as $table) {
                if ($this->db->one("SELECT id FROM $table WHERE INSTR(source, ?) > 0 OR INSTR(rendered_html, ?) > 0 LIMIT 1", [$pattern, $pattern])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function conflict(): never
    {
        throw new HttpError(409, 'media_conflict', 'O arquivo tem vínculos ou mudou desde a verificação. Atualize a lista.');
    }
}
