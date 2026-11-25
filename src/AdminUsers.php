<?php
declare(strict_types=1);

namespace MDAWiki;

final class AdminUsers
{
    private const PAGE_SIZE = 30;

    public function __construct(private Database $db, private array $config)
    {
    }

    public function list(array $query): array
    {
        $search = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 150) : '';
        $sort = ($query['sort'] ?? null) === 'created_at' ? 'created_at' : "COALESCE(NULLIF(name, ''), display_name, email)";
        $direction = ($query['direction'] ?? null) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, min(100000, (int) (filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1)));
        $filters = [];
        $parameters = [];
        if ($search !== '') {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $filters[] = "(name LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern, $pattern];
        }
        $photoFilter = $query['photo'] ?? null;
        if ($photoFilter === 'yes') $filters[] = "NULLIF(TRIM(avatar_filename), '') IS NOT NULL";
        if ($photoFilter === 'no') $filters[] = "NULLIF(TRIM(avatar_filename), '') IS NULL";
        $role = $query['role'] ?? null;
        if (in_array($role, ['admin', 'reader', 'user'], true)) {
            $filters[] = 'role = ?';
            $parameters[] = $role;
        }
        $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
        $total = (int) $this->db->one('SELECT COUNT(*) AS total FROM users' . $where, $parameters)['total'];
        $page = min($page, max(1, (int) ceil($total / self::PAGE_SIZE)));
        $items = $this->db->rows("SELECT id, name, display_name, email, created_at, public_id, is_active, role, NULLIF(TRIM(avatar_filename), '') AS FOTO
            FROM users" . $where . " ORDER BY $sort $direction, id $direction LIMIT ? OFFSET ?",
            [...$parameters, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);
        foreach ($items as &$item) {
            $item['photo'] = Accounts::photoUrl($item, $this->config);
            unset($item['FOTO']);
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => self::PAGE_SIZE];
    }

    public function change(int $actor, array $body): array
    {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = $body['action'] ?? null;
        $role = $body['role'] ?? null;
        if ($id === false || !in_array($action, ['delete', 'role'], true)
            || ($action === 'role' && !in_array($role, ['reader', 'user', 'admin'], true))) {
            throw new HttpError(422, 'invalid_user_action', 'Usuário ou papel inválido.');
        }
        if ($id === $actor) {
            throw new HttpError(403, 'self_user_change', 'Você não pode excluir sua própria conta nem alterar seu próprio papel.');
        }

        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $user = $this->db->one('SELECT id, avatar_filename FROM users WHERE id = ?' . $lock, [$id]);
            if (!$user) throw new HttpError(404, 'user_not_found', 'Usuário não encontrado.');
            if ($action === 'role') {
                $this->db->run('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$role, $id]);
            } else {
                $this->delete($user, $lock);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['id' => $id, 'deleted' => $action === 'delete'];
    }

    private function delete(array $user, string $lock): void
    {
        $avatar = $user['avatar_filename'];
        $this->db->run('DELETE FROM users WHERE id = ?', [$user['id']]);
        if (!$avatar || str_contains($avatar, '/') || !MediaStorage::validKey('users/' . $avatar)) return;
        if ($this->db->one('SELECT id FROM users WHERE avatar_filename = ? LIMIT 1' . $lock, [$avatar])) return;

        $key = 'users/' . $avatar;
        $file = $this->db->one('SELECT id FROM files WHERE storage_key = ?' . $lock, [$key]);
        if ($file && ($this->db->one('SELECT id FROM images WHERE file_id = ? LIMIT 1', [$file['id']])
            || $this->db->one('SELECT id FROM image_revisions WHERE file_id = ? LIMIT 1', [$file['id']]))) return;
        if ($file) $this->db->run('DELETE FROM files WHERE id = ?', [$file['id']]);
        (new MediaStorage($this->config))->delete($key);
    }
}
