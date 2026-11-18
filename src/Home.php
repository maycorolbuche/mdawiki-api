<?php
declare(strict_types=1);
namespace MDAWiki;

/** Homepage blocks, using the existing article and daily access indexes. */
final class Home
{
    public function __construct(private Database $db, private WikiRenderer $renderer, private string $filter) {}

    public function get(): array
    {
        $columns = "p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title,p.CONTEUDO_TXT AS description,COALESCE(p.DT_ALTERACAO,p.DT_CADASTRO) AS updated_at,
            (SELECT NULLIF(i.IMAGEM_FILE_URL,'') FROM PAGINAS_IMAGENS i WHERE i.IDENT=p.IDENT AND i.TEMPLATE='N' ORDER BY i.ID LIMIT 1) AS image";
        $random = $this->db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'RAND()' : 'RANDOM()';
        $eligible = $this->filter." AND NOT EXISTS (SELECT 1 FROM PAGINAS_REDIRECIONAMENTOS r WHERE r.IDENT=p.IDENT)";
        $result = [
            'random' => $this->db->rows("SELECT $columns FROM PAGINAS p WHERE $eligible ORDER BY $random LIMIT 3"),
            'recent' => $this->db->rows("SELECT $columns FROM PAGINAS p WHERE {$this->filter} ORDER BY COALESCE(p.DT_ALTERACAO,p.DT_CADASTRO) DESC,p.IDENT LIMIT 10"),
            'popular' => $this->db->rows("SELECT $columns FROM PAGINAS p WHERE {$this->filter} ORDER BY p.ACESSOS DESC,p.IDENT LIMIT 10"),
        ];
        $today = date('Y-m-d');
        foreach (['top_today'=>0,'top_week'=>6,'top_month'=>29] as $key=>$days) {
            $from = date('Y-m-d',strtotime("-$days days"));
            $result[$key] = $this->db->rows("SELECT $columns,a.views FROM PAGINAS p JOIN
                (SELECT IDENT,SUM(COALESCE(QTD_ACESSOS,0)) AS views FROM PAGINAS_ACESSOS_LOGS_QTD_DIARIO WHERE DATA >= ? AND DATA <= ? GROUP BY IDENT) a ON a.IDENT=p.IDENT
                WHERE {$this->filter} AND a.views>0 ORDER BY a.views DESC,p.IDENT LIMIT 10",[$from,$today]);
        }
        $result['missing'] = $this->db->rows("SELECT l.LINK_IDENT AS id,MIN(l.LINK_URL) AS slug,MIN(l.LINK) AS title
            FROM PAGINAS_LINKS l JOIN PAGINAS p ON p.IDENT=l.IDENT LEFT JOIN PAGINAS target ON target.IDENT=l.LINK_IDENT
            WHERE {$this->filter} AND target.IDENT IS NULL AND COALESCE(l.LINK_URL,'')<>'' AND l.LINK_IDENT NOT LIKE '#%' AND l.LINK_IDENT NOT LIKE 'page@%'
            GROUP BY l.LINK_IDENT ORDER BY title LIMIT 10");
        foreach ($result as &$items) foreach ($items as &$item) {
            $text = strip_tags($item['description'] ?? '');
            $item['description'] = mb_substr($text,0,300).(mb_strlen($text)>300 ? '...' : '');
            $item['image'] = empty($item['image']) ? null : $this->renderer->mediaUrl($item['image']);
            if (isset($item['views'])) $item['views'] = (int)$item['views'];
        }
        return $result;
    }
}
