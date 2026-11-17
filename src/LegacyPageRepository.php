<?php
declare(strict_types=1);

namespace MDAWiki;

use PDO;
use InvalidArgumentException;

final class LegacyPageRepository implements PageRepository
{
    private const COLUMNS = 'IDENT AS id, NOME_URL AS slug, NOME AS title, CONTEUDO AS html, CONTEUDO_TXT AS text, DT_ATUALIZACAO AS updated_at';
    private const PUBLIC_FILTER = "BUSCA = 'S' AND COALESCE(NIVEL, 1) <= :level AND IDENT NOT LIKE 'page@%' AND IDENT NOT LIKE 'imagem@%'";

    public function __construct(private readonly PDO $db, private readonly int $maxLevel = 1)
    {
    }

    /** Reuses the existing schema; connection encoding is explicitly configurable. */
    public static function connect(array $config, int $maxLevel): self
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
        return new self($db, $maxLevel);
    }

    public function list(int $limit, string $after): array
    {
        $query = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM PAGINAS WHERE ' . self::PUBLIC_FILTER . ' AND IDENT > :after ORDER BY IDENT LIMIT :limit');
        $query->bindValue(':level', $this->maxLevel, PDO::PARAM_INT);
        $query->bindValue(':after', $this->databaseValue($after));
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        return array_map($this->normalize(...), $query->fetchAll());
    }

    public function find(string $slug): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM PAGINAS WHERE ' . self::PUBLIC_FILTER . ' AND NOME_URL = :slug LIMIT 1');
        $query->bindValue(':level', $this->maxLevel, PDO::PARAM_INT);
        $query->bindValue(':slug', $this->databaseValue($slug));
        $query->execute();
        $row = $query->fetch();
        return $row ? $this->normalize($row) : null;
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
