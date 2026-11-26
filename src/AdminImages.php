<?php
declare(strict_types=1);

namespace MDAWiki;

final class AdminImages
{
    private const PAGE_SIZE = 24;

    public function __construct(private Database $db, private array $config)
    {
    }

    public function list(array $query): array
    {
        $field = ($query['sort'] ?? null) === 'size' ? 'f.size_bytes' : 'i.created_at';
        $direction = ($query['direction'] ?? null) === 'asc' ? 'ASC' : 'DESC';
        $total = (int) $this->db->one('SELECT COUNT(*) AS total FROM images')['total'];
        $page = max(1, min((int) ($query['page'] ?? 1), (int) ceil($total / self::PAGE_SIZE)));
        $items = $this->db->rows("SELECT i.id, i.slug, i.title, i.created_at, i.meta, i.access_level_id,
                f.size_bytes, f.mime_type, f.storage_key
            FROM images i LEFT JOIN files f ON f.id = i.file_id
            ORDER BY ($field IS NULL), $field $direction, i.id $direction LIMIT ? OFFSET ?",
            [self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);
        foreach ($items as &$item) {
            $meta = json_decode($item['meta'], true) ?? [];
            $item['width'] = $meta['width'] ?? null;
            $item['height'] = $meta['height'] ?? null;
            $item['extension'] = $meta['extension'] ?? ($item['storage_key'] ? pathinfo($item['storage_key'], PATHINFO_EXTENSION) : null);
            $item['has_file'] = $item['storage_key'] !== null;
            $item['is_public'] = (int) $item['access_level_id'] <= (int) $this->config['public_max_level'];
            unset($item['meta'], $item['storage_key'], $item['access_level_id']);
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => self::PAGE_SIZE];
    }

    public function original(mixed $value): Response
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new HttpError(422, 'invalid_image', 'Imagem inválida.');
        $file = $this->db->one('SELECT f.storage_key FROM images i JOIN files f ON f.id = i.file_id WHERE i.id = ?', [$id]);
        $key = $file['storage_key'] ?? '';
        $type = MediaStorage::contentType($key);
        $bytes = $type === null ? null : (new MediaStorage($this->config))->read($key);
        if ($bytes === null) throw new HttpError(404, 'media_not_found', 'Arquivo de imagem não encontrado.');
        return new Response(200, [], [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        ], $bytes);
    }
}
