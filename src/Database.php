<?php
declare(strict_types=1);
namespace MDAWiki;
use PDO;
final class Database
{
    private bool $latin;
    public function __construct(public readonly PDO $pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->latin = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $pdo->query('SELECT @@character_set_client')->fetchColumn() === 'latin1';
    }
    public function run(string $sql, array $values = []): \PDOStatement {
        $q = $this->pdo->prepare($sql);
        foreach ($values as $key => $v) {
            if ($this->latin && is_string($v)) $v = mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8');
            $q->bindValue(is_int($key) ? $key + 1 : $key, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $q->execute(); return $q;
    }
    public function rows(string $sql, array $values = []): array {
        return array_map(static function ($row) { foreach ($row as &$v) if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'); return $row; }, $this->run($sql, $values)->fetchAll());
    }
    public function one(string $sql, array $values = []): ?array { return $this->rows($sql, $values)[0] ?? null; }
    public static function id(string $value): string {
        $value = strtr($value, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C']);
        return mb_strtolower(str_replace(["'", '"', ' '], ['', '', '_'], trim($value)), 'UTF-8');
    }
}
