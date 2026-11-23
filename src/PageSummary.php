<?php
declare(strict_types=1);

namespace MDAWiki;

final class PageSummary
{
    public static function shorten(?string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text ?? '') ?? '');
        if (mb_strlen($text) <= $limit) return $text;

        $prefix = mb_substr($text, 0, $limit);
        if (!str_ends_with($prefix, ' ')) {
            $remainder = mb_substr($text, $limit);
            if (preg_match('/^\S+/u', $remainder, $wordEnd)) {
                $prefix .= $wordEnd[0];
                if ($prefix === $text) return $text;
            }
        }
        return rtrim($prefix) . '...';
    }
}
