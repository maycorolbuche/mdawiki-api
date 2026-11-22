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
        $image = (new NormalizedImages($this->db, []))->lookup($legacyName);
        if (!$image || (int) $image['access_level_id'] > $this->level) {
            return null;
        }
        return ['image_id' => (int) $image['id'], 'slug' => $image['slug'], 'storage_key' => $image['storage_key'] ?? Media::MISSING_IMAGE];
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
                $body = $anchor[2];
                if (str_starts_with(mb_strtolower($slug), 'imagem@') || str_starts_with(mb_strtolower($slug), 'i/')) {
                    $name = preg_replace('~^(?:imagem@|i/)~iu', '', $slug);
                    $file = $this->image($name);
                    $attrs = str_replace($href[0], ' href="' . WikiRenderer::e($this->frontendUrl . '/i/' . rawurlencode($file['slug'] ?? $name)) . '"', $anchor[1]);
                    if ($file) {
                        $body = preg_replace_callback('~(<img\b[^>]*?\s+src\s*=\s*)(["\x27])([^"\x27]*)\2~i',
                            function (array $src) use ($file): string {
                                if (!preg_match('~(?:@imgs_wiki(?:_mini|_cp)?/|/(?:wiki|wiki_mini|wiki_cp)/|/articles/[a-f0-9]{64}\.|img_nao_encontrada\.|^imagem@)~i', $src[3])) {
                                    return $src[0];
                                }
                                return $src[1] . '"' . WikiRenderer::e($this->mediaUrl . '/' . $file['storage_key']) . '"';
                            }, $body);
                    }
                    return '<a' . $attrs . '>' . $body . '</a>';
                }
                if ($this->target($slug)) {
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
        $name = preg_replace('~^i/~i', '', $slug);
        return $name === '' || !mb_check_encoding($slug, 'UTF-8') || mb_strlen($slug) > 250
            || preg_match('~[/\\\\\x00-\x1f]~', $name) || str_starts_with(mb_strtolower($slug), 'page@')
            ? null : $slug;
    }
}
