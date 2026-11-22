<?php
declare(strict_types=1);

namespace MDAWiki;

final class NormalizedArticleHtml
{
    private array $targets = [];

    public function __construct(private Database $db, private int $level, private string $mediaUrl, private string $frontendUrl)
    {
    }

    public function image(string $legacyName): ?array
    {
        $page = str_starts_with(mb_strtolower($legacyName), 'imagem@')
            ? $this->db->one("SELECT id, kind, access_level_id FROM pages WHERE legacy_identifier = ? AND kind = 'image'", [Database::id($legacyName)])
            : null;
        $page ??= $this->target($legacyName);
        if (!$page || $page['kind'] !== 'image' || (int) ($page['access_level_id'] ?? 1) > $this->level) {
            return null;
        }
        $file = $this->db->one('SELECT f.storage_key FROM images i JOIN files f ON f.id = i.file_id WHERE i.page_id = ?', [$page['id']]);
        if (!$file || !preg_match('~^articles/[a-f0-9]{64}\.(?:avif|gif|jpe?g|png|svg|webp)$~D', $file['storage_key'])) {
            return null;
        }
        return ['page_id' => (int) $page['id'], 'storage_key' => $file['storage_key']];
    }

    private function target(string $slug): ?array
    {
        if (!array_key_exists($slug, $this->targets)) {
            $this->targets[$slug] = NormalizedPageRepository::lookup($this->db, $slug);
        }
        return $this->targets[$slug];
    }

    public function decorate(string $html): array
    {
        $missing = [];
        $decorated = preg_replace_callback('~<a\b((?:"[^"]*"|\x27[^\x27]*\x27|[^>\x22\x27])*+)>(.*?)</a\s*>~is',
            function (array $anchor) use (&$missing): string {
                if (!preg_match('~\s+href\s*=\s*(["\x27])(.*?)\1~is', $anchor[1], $href)) {
                    return $anchor[0];
                }
                $slug = $this->internalSlug(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($slug === null) {
                    return $anchor[0];
                }
                $target = $this->target($slug);
                $body = $anchor[2];
                if (stripos($body, '<img') !== false && (($target['kind'] ?? null) === 'image' || str_starts_with(mb_strtolower($slug), 'imagem@'))) {
                    $file = $this->image($slug);
                    if ($file) {
                        $body = preg_replace_callback('~(<img\b[^>]*?\s+src\s*=\s*)(["\x27])([^"\x27]*)\2~i',
                            function (array $src) use ($file): string {
                                if (!preg_match('~(?:@imgs_wiki(?:_mini|_cp)?/|/(?:wiki|wiki_mini|wiki_cp)/|img_nao_encontrada\.|^imagem@)~i', $src[3])) {
                                    return $src[0];
                                }
                                return $src[1] . '"' . WikiRenderer::e($this->mediaUrl . '/' . $file['storage_key']) . '"';
                            }, $body);
                    }
                    return '<a' . $anchor[1] . '>' . $body . '</a>';
                }
                if ($target) {
                    return $anchor[0];
                }
                $id = Database::id($slug);
                $title = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $missing[$id] ??= ['id' => $id, 'slug' => $slug, 'title' => $title !== '' ? $title : str_replace('_', ' ', $slug)];
                $attrs = $anchor[1];
                if (preg_match('~(\sclass\s*=\s*)(["\x27])(.*?)\2~is', $attrs)) {
                    $attrs = preg_replace_callback('~(\sclass\s*=\s*)(["\x27])(.*?)\2~is',
                        static fn (array $class): string => $class[1] . $class[2] . $class[3] . ' text-danger' . $class[2], $attrs, 1);
                } else {
                    $attrs .= ' class="text-danger"';
                }
                return '<a' . $attrs . '>' . $body . '</a>';
            }, $html);
        return ['html' => $decorated ?? $html, 'missing_links' => array_values($missing)];
    }

    private function internalSlug(string $href): ?string
    {
        if (str_starts_with($href, '{{{url_site}}}')) {
            $href = $this->frontendUrl . '/' . ltrim(substr($href, strlen('{{{url_site}}}')), '/');
        }
        $url = parse_url($href);
        if ($url === false || isset($url['user']) || isset($url['pass'])
            || (isset($url['scheme']) && !in_array(strtolower($url['scheme']), ['http', 'https'], true))) {
            return null;
        }
        $path = $url['path'] ?? '';
        if (isset($url['host'])) {
            $host = strtolower($url['host']);
            if (!in_array($host, ['mda.wiki.br', 'www.mda.wiki.br', strtolower((string) parse_url($this->frontendUrl, PHP_URL_HOST))], true)) {
                return null;
            }
            $base = $host === strtolower((string) parse_url($this->frontendUrl, PHP_URL_HOST))
                ? rtrim((string) parse_url($this->frontendUrl, PHP_URL_PATH), '/') : '';
            if ($base !== '' && str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base));
            }
        } elseif (!str_starts_with($path, '/')) {
            return null;
        }
        $slug = rawurldecode(trim(preg_replace('~^/site/~i', '/', $path), '/'));
        return $slug === '' || !mb_check_encoding($slug, 'UTF-8') || mb_strlen($slug) > 250
            || preg_match('~[/\\\\\x00-\x1f]~', $slug) || str_starts_with(mb_strtolower($slug), 'page@')
            ? null : $slug;
    }
}
