<?php
declare(strict_types=1);

namespace MDAWiki;

use Normalizer;
use RuntimeException;

final class PageSlugs
{
    public static function fromTitle(?string $title, string $fallback): array
    {
        foreach ([$title ?? '', $fallback] as $value) {
            $canonical = Normalizer::normalize($value, Normalizer::FORM_C);
            $slug = trim(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $canonical), '-');
            $folded = mb_convert_case($slug, MB_CASE_FOLD, 'UTF-8');
            $identifier = preg_replace('/\p{M}+/u', '', Normalizer::normalize($folded, Normalizer::FORM_D));
            if ($identifier !== '') {
                return [$slug, Normalizer::normalize($identifier, Normalizer::FORM_C)];
            }
        }
        throw new RuntimeException('Título e URL legada não geraram um identificador válido.');
    }
}
