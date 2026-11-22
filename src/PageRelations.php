<?php
declare(strict_types=1);

namespace MDAWiki;

final class PageRelations
{
    public function __construct(private Database $db)
    {
    }

    public function replace(int $pageId, string $source, array $rendered): void
    {
        foreach (['page_categories', 'page_links', 'page_external_links', 'page_redirects', 'page_includes',
            'page_templates', 'page_headings', 'page_media', 'page_videos'] as $table) {
            $this->db->run("DELETE FROM {$table} WHERE page_id = ?", [$pageId]);
        }

        $categories = [];
        foreach ($rendered['categories'] as $category) {
            $id = $this->target($category['slug']);
            if ($id !== null && !isset($categories[$id])) {
                $this->db->run('INSERT INTO page_categories (page_id, category_id, position) VALUES (?, ?, ?)',
                    [$pageId, $id, count($categories) + 1]);
                $categories[$id] = true;
            }
        }
        foreach ($rendered['links'] as $i => $link) {
            $id = $this->target($link['slug']);
            if ($id !== null) {
                $this->db->run('INSERT INTO page_links (page_id, target_page_id, position, fragment, link_text)
                    VALUES (?, ?, ?, ?, ?)', [$pageId, $id, $i + 1, self::field($link['hash']), self::field($link['title'])]);
            }
        }
        foreach ($rendered['external_links'] as $i => $link) {
            $this->db->run('INSERT INTO page_external_links (page_id, position, href, link_text) VALUES (?, ?, ?, ?)',
                [$pageId, $i + 1, self::field($link['url']), self::field($link['title'])]);
        }
        if ($rendered['redirect'] !== null) {
            $id = $this->target($rendered['redirect']);
            if ($id !== null && $id !== $pageId) {
                $this->db->run('INSERT INTO page_redirects (page_id, target_page_id, position) VALUES (?, ?, 1)', [$pageId, $id]);
            }
        }

        preg_match_all('~{{Incluir:([^{}]+)}}~iu', $source, $includes);
        foreach ($includes[1] as $i => $include) {
            [$name, $section] = array_pad(explode('#', trim($include), 2), 2, '');
            $id = $this->target(trim($name));
            if ($id !== null && $id !== $pageId) {
                $this->db->run('INSERT INTO page_includes (page_id, target_page_id, position, section_anchor) VALUES (?, ?, ?, ?)',
                    [$pageId, $id, $i + 1, self::field($section)]);
            }
        }
        foreach ($rendered['templates'] as $i => $template) {
            $this->db->run('INSERT INTO page_templates (page_id, position, template_key, template_source) VALUES (?, ?, ?, ?)',
                [$pageId, $i + 1, self::field(Database::id($template)), self::field('{{' . $template . '}}')]);
        }
        foreach ($rendered['headings'] as $i => $heading) {
            $this->db->run('INSERT INTO page_headings (page_id, position, title, heading_key, anchor, heading_level)
                VALUES (?, ?, ?, ?, ?, ?)', [$pageId, $i + 1, self::field($heading['title']),
                    self::field($heading['id']), self::field($heading['id']), $heading['level']]);
        }
        foreach ($rendered['videos'] as $i => $video) {
            $this->db->run('INSERT INTO page_videos (page_id, position, url) VALUES (?, ?, ?)', [$pageId, $i + 1, self::field($video)]);
        }
        $this->replaceMedia($pageId, $rendered);
    }

    private function replaceMedia(int $pageId, array $rendered): void
    {
        $refs = $rendered['image_refs'] ?? [];
        $urls = array_fill_keys(array_map(static fn (array $ref) => (string) parse_url($ref['url'], PHP_URL_PATH), $refs), true);
        $position = 0;
        foreach ($refs as $ref) {
            $id = $ref['page_id'] ?? null;
            if ($id !== null) {
                $this->db->run('INSERT INTO page_media (page_id, image_page_id, position, is_template) VALUES (?, ?, ?, ?)',
                    [$pageId, $id, ++$position, (int) ($ref['template'] === 'S')]);
            }
        }
        foreach ($rendered['images'] as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (isset($urls[$path]) || !is_string($path) || !preg_match('~(?:^|/)(articles/[a-f0-9]{64}\\.[a-z0-9]+)$~D', $path, $match)) {
                continue;
            }
            foreach ($this->db->rows('SELECT i.page_id FROM images i JOIN files f ON f.id = i.file_id
                WHERE f.storage_key = ? ORDER BY i.page_id', [$match[1]]) as $row) {
                $this->db->run('INSERT INTO page_media (page_id, image_page_id, position, is_template) VALUES (?, ?, ?, 0)',
                    [$pageId, $row['page_id'], ++$position]);
            }
        }
    }

    private function target(string $slug): ?int
    {
        $page = NormalizedPageRepository::lookup($this->db, $slug);
        return $page === null ? null : (int) $page['id'];
    }

    private static function field(string $value): string
    {
        return mb_substr($value, 0, 250);
    }
}
