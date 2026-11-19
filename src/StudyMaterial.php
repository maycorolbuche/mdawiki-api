<?php
declare(strict_types=1);

namespace MDAWiki;

use Closure;
use Throwable;

/** The same code-to-metadata lookup used by legacy data.php. */
final class StudyMaterial
{
    public function __construct(private string $storage, private ?Closure $fetch = null) {}

    public static function code(string $html): ?string
    {
        if (!preg_match('~<template\b[^>]*\bclass\s*=\s*[\'"]mda-code[\'"][^>]*>(.*?)</template>~is', $html, $match)) return null;
        $code = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,39}$/D', $code) ? $code : null;
    }

    public function lookup(string $html): ?string
    {
        $code = self::code($html);
        if ($code === null) return null;
        $directory = $this->storage . '/study-material';
        $file = $directory . '/' . hash('sha256', $code) . '.json';
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) return self::validUrl($cached['url'] ?? null);
        try {
            $json = $this->fetch ? ($this->fetch)('https://plus.mda.wiki.br/meta/' . rawurlencode($code) . '.json') : self::download($code);
            // Transport/invalid JSON failures keep the last valid link; retry after five minutes.
            $data = is_string($json) ? json_decode($json, true) : null;
            $url = is_array($data) ? self::validUrl($data['url'] ?? null) : self::validUrl($cached['url'] ?? null);
        } catch (Throwable) {
            $data = null;
            $url = self::validUrl($cached['url'] ?? null);
        }
        if (is_dir($directory) || @mkdir($directory, 0700, true)) {
            @file_put_contents($file, json_encode(['url' => $url, 'expires' => time() + ($url && is_array($data) ? 3600 : 300)]), LOCK_EX);
        }
        return $url;
    }

    private static function validUrl(mixed $url): ?string
    {
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'plus.mda.wiki.br'
            && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443) ? $url : null;
    }

    private static function download(string $code): string|false
    {
        $url = 'https://plus.mda.wiki.br/meta/' . rawurlencode($code) . '.json';
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 800, CURLOPT_TIMEOUT_MS => 2000, CURLOPT_FOLLOWLOCATION => false]);
            $json = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            return $status === 404 ? '{}' : ($status === 200 && is_string($json) && strlen($json) <= 65536 ? $json : false);
        }
        $context = stream_context_create(['http' => ['timeout' => 2, 'follow_location' => 0, 'ignore_errors' => true]]);
        $json = @file_get_contents($url, false, $context, 0, 65537);
        $status = $http_response_header[0] ?? '';
        return preg_match('~^HTTP/\S+ 404\b~', $status) ? '{}' : (preg_match('~^HTTP/\S+ 200\b~', $status) && is_string($json) && strlen($json) <= 65536 ? $json : false);
    }
}
