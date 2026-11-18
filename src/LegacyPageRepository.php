<?php
declare(strict_types=1);

namespace MDAWiki;

use PDO;
use InvalidArgumentException;

final class LegacyPageRepository implements DiagnosablePageRepository
{
    private const COLUMNS = 'IDENT AS id, NOME_URL AS slug, NOME AS title, CONTEUDO AS html, CONTEUDO_TXT AS text, DT_ATUALIZACAO AS updated_at';
    private const PUBLIC_FILTER = "BUSCA = 'S' AND COALESCE(NIVEL, 1) <= :level AND IDENT NOT LIKE 'page@%'";

    public function __construct(private readonly PDO $db, private readonly int $maxLevel = 1, private readonly ?array $siteConfig = null)
    {
    }
    public function connection(): PDO { return $this->db; }

    /** Reuses the existing schema; connection encoding is explicitly configurable. */
    public static function connect(array $config, int $maxLevel, ?array $siteConfig = null): self
    {
        // Charset controls the legacy connection behavior; output is normalized below.
        if (!in_array($config['charset'], ['latin1', 'utf8mb4', 'utf8'], true)) {
            throw new InvalidArgumentException('Unsupported database connection charset.');
        }
        foreach (['host', 'port', 'database'] as $key) {
            if (str_contains($config[$key], ';')) {
                throw new InvalidArgumentException('Invalid database configuration.');
            }
        }
        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
        return new self($db, $maxLevel, $siteConfig);
    }

    public function list(int $limit, string $after): array
    {
        $query = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM PAGINAS WHERE ' . self::PUBLIC_FILTER . ' AND IDENT > :after ORDER BY IDENT LIMIT :limit');
        $query->bindValue(':level', $this->maxLevel, PDO::PARAM_INT);
        $query->bindValue(':after', $this->databaseValue($after));
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        return $this->withRelations(array_map($this->normalize(...), $query->fetchAll()));
    }

    public function find(string $slug): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM PAGINAS WHERE ' . self::PUBLIC_FILTER . ' AND NOME_URL = :slug LIMIT 1');
        $query->bindValue(':level', $this->maxLevel, PDO::PARAM_INT);
        $query->bindValue(':slug', $this->databaseValue($slug));
        $query->execute();
        $row = $query->fetch();
        return $row ? $this->withRelations([$this->normalize($row)])[0] : null;
    }

    /** Fetch relations once per export batch, preserving the existing tables. */
    private function withRelations(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_map(fn ($row) => $this->databaseValue($row['id']), $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $public = "p.BUSCA = 'S' AND COALESCE(p.NIVEL, 1) <= ? AND p.IDENT NOT LIKE 'page@%' AND p.IDENT NOT LIKE 'imagem@%'";
        $queries = [
            'categories' => ["SELECT c.IDENT AS owner, c.CATEGORIA_IDENT AS id, c.CATEGORIA_URL AS slug, c.CATEGORIA AS title FROM PAGINAS_CATEGORIAS c LEFT JOIN PAGINAS p ON p.IDENT = c.CATEGORIA_IDENT WHERE c.IDENT IN ($placeholders) AND (p.IDENT IS NULL OR ($public)) ORDER BY c.ORDEM, c.CATEGORIA", true],
            'mentions' => ["SELECT DISTINCT l.LINK_IDENT AS owner, p.IDENT AS id, p.NOME_URL AS slug, p.NOME AS title FROM PAGINAS_LINKS l JOIN PAGINAS p ON p.IDENT = l.IDENT WHERE l.LINK_IDENT IN ($placeholders) AND $public ORDER BY p.NOME, p.IDENT", true],
            'related_pages' => ["SELECT DISTINCT related.CATEGORIA_IDENT AS owner, p.IDENT AS id, p.NOME_URL AS slug, p.NOME AS title FROM PAGINAS_CATEGORIAS related JOIN PAGINAS p ON p.IDENT = related.IDENT WHERE related.CATEGORIA_IDENT IN (SELECT CATEGORIA_IDENT FROM PAGINAS_CATEGORIAS WHERE IDENT IN ($placeholders)) AND $public ORDER BY p.NOME, p.IDENT", true],
            'category_members' => ["SELECT DISTINCT c.CATEGORIA_IDENT AS owner, p.IDENT AS id, p.NOME_URL AS slug, p.NOME AS title FROM PAGINAS_CATEGORIAS c JOIN PAGINAS p ON p.IDENT = c.IDENT WHERE c.CATEGORIA_IDENT IN ($placeholders) AND $public ORDER BY p.NOME, p.IDENT", true],
            'missing_links' => ["SELECT DISTINCT l.IDENT AS owner, l.LINK_IDENT AS id, l.LINK_URL AS slug, l.LINK_TXT AS title FROM PAGINAS_LINKS l LEFT JOIN PAGINAS p ON p.IDENT = l.LINK_IDENT WHERE l.IDENT IN ($placeholders) AND p.IDENT IS NULL AND COALESCE(l.LINK_URL, '') <> '' AND l.LINK_IDENT NOT LIKE '#%' ORDER BY l.LINK_TXT", false],
        ];
        $relations = [];
        $categoryOwners = [];
        $seenRelated = [];
        foreach ($queries as $key => [$sql, $hasLevel]) {
            $query = $this->db->prepare($sql);
            foreach ($ids as $index => $id) $query->bindValue($index + 1, $id);
            if ($hasLevel) $query->bindValue(count($ids) + 1, $this->maxLevel, PDO::PARAM_INT);
            $query->execute();
            while ($raw = $query->fetch()) {
                $link = $this->normalize($raw);
                $owner = $link['owner'];
                unset($link['owner']);
                $link['title'] = html_entity_decode($link['title'] ?: str_replace('_', ' ', $link['slug']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($key === 'categories') $categoryOwners[$link['id']][] = $owner;
                if ($key === 'related_pages') {
                    // Candidates are fetched once per category, avoiding the
                    // article x category-members cross product during builds.
                    foreach ($categoryOwners[$owner] ?? [] as $article) {
                        if ($article !== $link['id'] && !isset($seenRelated[$article][$link['id']]) && count($relations[$article][$key] ?? []) < 5) {
                            $relations[$article][$key][] = $link;
                            $seenRelated[$article][$link['id']] = true;
                        }
                    }
                } else {
                    $relations[$owner][$key][] = $link;
                }
            }
        }
        foreach ($rows as &$row) {
            foreach (array_keys($queries) as $key) $row[$key] = $relations[$row['id']][$key] ?? [];
            if ($this->siteConfig && str_contains($row['html'] ?? '', '{{{lista:')) $row['html'] = (new PublicLists(new Database($this->db),$this->siteConfig))->render($row['html']);
        }
        $redirectQuery=$this->db->prepare("SELECT IDENT,PAGINA_URL FROM PAGINAS_REDIRECIONAMENTOS WHERE ID = 1 AND IDENT IN ($placeholders)");
        foreach ($ids as $index=>$id) $redirectQuery->bindValue($index+1,$id);
        $redirectQuery->execute();$redirects=[];
        foreach ($redirectQuery->fetchAll() as $redirect) { $redirect=$this->normalize($redirect);$redirects[$redirect['IDENT']]=$redirect['PAGINA_URL']; }
        unset($row);
        foreach ($rows as &$row) $row['redirect_slug']=$redirects[$row['id']] ?? null;
        unset($row);
        $mediaIds=[];foreach($rows as $row) if(str_starts_with($row['id'],'imagem@')) $mediaIds[]=substr($row['id'],7);
        $usage=[];
        if ($mediaIds) {
            $marks=implode(',',array_fill(0,count($mediaIds),'?'));
            $query=$this->db->prepare("SELECT DISTINCT i.IMAGEM_IDENT AS owner,p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title FROM PAGINAS_IMAGENS i JOIN PAGINAS p ON p.IDENT=i.IDENT WHERE i.IMAGEM_IDENT IN ($marks) AND p.BUSCA='S' AND COALESCE(p.NIVEL,1) <= ? AND p.IDENT NOT LIKE 'page@%' ORDER BY p.NOME,p.IDENT");
            foreach($mediaIds as $index=>$id) $query->bindValue($index+1,$this->databaseValue($id));$query->bindValue(count($mediaIds)+1,$this->maxLevel,PDO::PARAM_INT);$query->execute();
            foreach($query->fetchAll() as $link) { $link=$this->normalize($link);$owner='imagem@'.$link['owner'];unset($link['owner']);$link['title']=html_entity_decode($link['title'],ENT_QUOTES|ENT_HTML5,'UTF-8');$usage[$owner][]=$link; }
        }
        foreach($rows as &$row) $row['image_usage']=$usage[$row['id']] ?? [];
        return $rows;
    }

    public function diagnose(string $slug): array
    {
        $query = $this->db->prepare('SELECT IDENT, NOME_URL, BUSCA, NIVEL FROM PAGINAS WHERE NOME_URL = :slug OR IDENT = :ident ORDER BY CASE WHEN NOME_URL = :exact THEN 0 ELSE 1 END LIMIT 1');
        $value = $this->databaseValue($slug);
        $query->execute(['slug' => $value, 'ident' => $value, 'exact' => $value]);
        $row = $query->fetch();
        if (!$row) {
            return ['record_found' => false, 'reason' => 'no_matching_record', 'lookup_field' => 'NOME_URL'];
        }
        $row = $this->normalize($row);
        $reasons = [];
        if ($row['NOME_URL'] !== $slug) {
            $reasons[] = 'identifier_is_not_url_slug';
        }
        if ($row['BUSCA'] !== 'S') {
            $reasons[] = 'not_searchable';
        }
        if ((int) ($row['NIVEL'] ?? 1) > $this->maxLevel) {
            $reasons[] = 'level_above_public_max';
        }
        if (str_starts_with(strtolower($row['IDENT']), 'page@')) {
            $reasons[] = 'special_page_not_exported';
        }
        return [
            'record_found' => true,
            'lookup_field' => 'NOME_URL',
            'reasons' => $reasons ?: ['database_comparison_requires_investigation'],
            'public_max_level' => $this->maxLevel,
        ];
    }

    private function databaseValue(string $value): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $this->db->query('SELECT @@character_set_client')->fetchColumn() === 'latin1'
            ? mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8') : $value;
    }

    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                // Preserve already-valid UTF-8 used by legacy conversions.
                $row[$key] = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            }
        }
        return $row;
    }
}


