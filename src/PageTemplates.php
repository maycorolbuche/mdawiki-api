<?php
declare(strict_types=1);

namespace MDAWiki;

final class PageTemplates
{
    private const FIELDS = [
        'pathfinder_honor' => ['area_code', 'code', 'icon', 'level', 'year', 'origin_institution', 'color_scheme', 'is_obsolete'],
        'adventurer_honor' => ['area_code', 'code', 'icon', 'color_scheme', 'is_obsolete'],
        'person' => ['display_name', 'photo', 'birth_date', 'death_date', 'achievements', 'positions'],
    ];
    private const POSITION_FIELDS = ['title', 'start_date', 'end_date', 'predecessor', 'successor'];

    public function __construct(private Database $db) {}

    public function load(int $pageId): array
    {
        $rows = $this->db->rows('SELECT template, meta FROM page_templates WHERE page_id = ?', [$pageId]);
        foreach ($rows as &$row) {
            $row['meta'] = json_decode($row['meta'], true, flags: JSON_THROW_ON_ERROR);
        }
        return self::validate($rows);
    }

    public function save(int $pageId, array $before, array $after): void
    {
        $previous = array_column($before, 'meta', 'template');
        $next = array_column($after, 'meta', 'template');
        foreach (array_diff_key($previous, $next) as $type => $_) {
            $this->db->run('DELETE FROM page_templates WHERE page_id = ? AND template = ?', [$pageId, $type]);
        }
        foreach ($next as $type => $meta) {
            if (($previous[$type] ?? null) === $meta) continue;
            $this->db->run('INSERT INTO page_templates (page_id, template, meta) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE meta = ?', [$pageId, $type, self::json((object) $meta), self::json((object) $meta)]);
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function validate(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)) self::invalid();
        $templates = [];
        foreach ($items as $item) {
            $type = $item['template'] ?? null;
            $meta = $item['meta'] ?? null;
            if (!is_array($item) || !is_string($type) || !isset(self::FIELDS[$type]) || isset($templates[$type])
                || !is_array($meta) || array_diff(array_keys($meta), self::FIELDS[$type])) self::invalid();
            $fields = [];
            foreach (self::FIELDS[$type] as $key) {
                $value = $meta[$key] ?? null;
                $fields[$key] = match ($key) {
                    'is_obsolete' => is_bool($value ?? false) ? ($value ?? false) : self::invalid(),
                    'achievements' => self::items($value, static fn ($item) => self::field($item, $key)),
                    'positions' => self::items($value, self::position(...)),
                    default => self::field($value, $key),
                };
            }
            $templates[$type] = ['template' => $type, 'meta' => $fields];
        }
        return array_values(array_replace(array_intersect_key(self::FIELDS, $templates), $templates));
    }

    private static function items(mixed $value, callable $parse): array
    {
        $value ??= [];
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) self::invalid();
        return array_map($parse, $value);
    }

    private static function position(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), self::POSITION_FIELDS)) self::invalid();
        $fields = [];
        foreach (self::POSITION_FIELDS as $key) $fields[$key] = self::field($value[$key] ?? null, $key);
        if ($fields['title'] === '') self::invalid();
        return $fields;
    }

    private static function field(mixed $value, string $key): string
    {
        $value ??= '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 1000) self::invalid();
        $value = trim($value);
        if (in_array($key, ['icon', 'photo'], true) && preg_match('~[<>"=\x27{}|\[\]\x00-\x1f]~u', $value)) self::invalid();
        if ($value !== '' && str_ends_with($key, '_date')
            && (!preg_match('~^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$~D', $value, $parts)
                || !checkdate((int) ($parts[2] ?? 1), (int) ($parts[3] ?? 1), (int) $parts[1]))) self::invalid();
        if (in_array($key, ['year', 'level'], true) && $value !== '' && !ctype_digit($value)) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new HttpError(422, 'invalid_page_templates', 'Confira os campos dos templates. Use apenas um template de cada tipo e datas no formato AAAA, AAAA-MM ou AAAA-MM-DD.');
    }

    // Use the existing honor and image renderers. Text fields are escaped before entering the wiki pipeline.
    public static function renderSource(array $templates): string
    {
        $blocks = [];
        foreach ($templates as ['template' => $type, 'meta' => $meta]) {
            $blocks[] = $type === 'person' ? self::person($meta) : self::honor($type, $meta);
        }
        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n\n";
    }

    private static function honor(string $type, array $meta): string
    {
        $values = ['Clube=' . ($type === 'adventurer_honor' ? 'AVT' : 'DBV')];
        foreach (['area_code' => 'Area', 'code' => 'Cod', 'icon' => 'Icone', 'level' => 'Nivel',
            'year' => 'Ano', 'origin_institution' => 'Origem', 'color_scheme' => 'Cores'] as $key => $label) {
            $value = $meta[$key] ?? '';
            if ($value !== '' || $key === 'area_code') $values[] = $label . '=' . ($key === 'icon' ? $value : self::escape($value));
        }
        return '{{Especialidade:' . implode('|', $values) . '}}'
            . ($meta['is_obsolete'] ? "\n{{Especialidade Obsoleta:}}" : '');
    }

    private static function person(array $meta): string
    {
        $html = '<div class="biography"><h4>' . self::escape($meta['display_name']) . '</h4>';
        if ($meta['photo'] !== '') $html .= '{{Imagem:' . $meta['photo'] . '}}';
        foreach (['birth_date' => 'Nascimento', 'death_date' => 'Morte'] as $key => $label) {
            if ($meta[$key] !== '') $html .= '<p><strong>' . $label . ':</strong> ' . self::escape($meta[$key]) . '</p>';
        }
        if ($meta['achievements'] !== []) {
            $html .= '<p><strong>Principais feitos</strong></p><ul>';
            foreach ($meta['achievements'] as $item) $html .= '<li>' . self::escape($item) . '</li>';
            $html .= '</ul>';
        }
        if ($meta['positions'] !== []) {
            $html .= '<p><strong>Cargos</strong></p><ul>';
            foreach ($meta['positions'] as $position) {
                $period = implode(' a ', array_filter([$position['start_date'], $position['end_date']]));
                $html .= '<li>' . ($period !== '' ? self::escape($period) . ' — ' : '') . self::escape($position['title']);
                foreach (['predecessor' => 'Antecessor', 'successor' => 'Sucessor'] as $key => $label) {
                    if ($position[$key] !== '') $html .= '<br>' . $label . ': ' . self::escape($position[$key]);
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    private static function escape(string $value): string
    {
        return strtr(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ['{' => '&#123;', '}' => '&#125;', '[' => '&#91;', ']' => '&#93;', '|' => '&#124;', '=' => '&#61;']);
    }
}
