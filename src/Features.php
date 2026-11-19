<?php
declare(strict_types=1);
namespace MDAWiki;
use Closure;
final class Features
{
    private Accounts $accounts;
    private Pages $pages;
    private WikiRenderer $renderer;
    private RateLimiter $rate;
    private LegacyMail $notifications;
    public function __construct(private Database $db, private Session $session, private array $config, Closure $mail) {
        $this->notifications=new LegacyMail($db,$config,$mail);$this->accounts=new Accounts($db,$session,$config,$mail,$this->notifications);$this->renderer=new WikiRenderer($db,$config);$this->pages=new Pages($db,$this->accounts,$this->renderer,$config,$this->notifications);$this->rate=new RateLimiter($config['storage_dir'].'/rates');
    }
    public function handle(string $method,string $path,array $query,array $body,string $csrf,string $ip): Response {
        try {
            if (!in_array($method,['GET','POST','PUT'],true)) throw new HttpError(405,'method_not_allowed','Método não permitido.');
            if ($method!=='GET') {
                $this->session->csrf($csrf);
                $auth=str_starts_with($path,'/v1/auth/');
                $this->rate->check(($auth?'auth:':'write:').$ip,$auth?20:100);
                if ($auth) $this->rate->check('account:'.hash('sha256',strtolower(is_string($body['email'] ?? null)?$body['email']:$ip)),20);
            }
            $data=match (true) {
                $method==='GET' && $path==='/v1/auth/session' => $this->accounts->session(),
                $method==='POST' && $path==='/v1/auth/login' => $this->accounts->login($body),
                $method==='POST' && $path==='/v1/auth/logout' => $this->accounts->logout(),
                $method==='POST' && $path==='/v1/auth/register' => $this->accounts->register($body),
                $method==='POST' && $path==='/v1/auth/activate' => $this->accounts->activation($body),
                $method==='POST' && $path==='/v1/auth/resend' => $this->accounts->requestCode($body,false),
                $method==='POST' && $path==='/v1/auth/forgot' => $this->accounts->requestCode($body,true),
                $method==='POST' && $path==='/v1/auth/reset' => $this->accounts->reset($body),
                $method==='GET' && $path==='/v1/profile' => $this->accounts->profile($this->accounts->current(true)),
                $method==='PUT' && $path==='/v1/profile' => $this->accounts->update($body),
                $method==='POST' && $path==='/v1/profile/password' => $this->accounts->changePassword($body),
                $method==='POST' && $path==='/v1/profile/email' => $this->accounts->changeEmail($body),
                $method==='POST' && $path==='/v1/profile/photo' => $this->photo($body),
                $method==='POST' && $path==='/v1/media' => $this->upload($body),
                $method==='GET' && $path==='/v1/search' => $this->search($query),
                $method==='GET' && $path==='/v1/catalog' => $this->catalog($query),
                $method==='GET' && $path==='/v1/statistics' => $this->statistics(),
                $method==='GET' && $path==='/v1/home' => $this->home(),
                $method==='POST' && $path==='/v1/visits' => $this->visit($body),
                $method==='GET' && preg_match('~^/v1/view/([^/]+)$~D',$path,$m)===1 => $this->view(rawurldecode($m[1])),
                $method==='GET' && preg_match('~^/v1/users(?:/(\d+))?$~D',$path,$m)===1 => $this->users($query,isset($m[1])?(int)$m[1]:null),
                preg_match('~^/v1/pages/([^/]+)/(editor|history|preview|restore|rebuild)$~D',$path,$m)===1 => $this->pageAction($method,rawurldecode($m[1]),$m[2],$query,$body),
                $method==='PUT' && preg_match('~^/v1/pages/([^/]+)$~D',$path,$m)===1 => $this->pages->save($this->slug(rawurldecode($m[1])),$body),
                default => throw new HttpError(404,'route_not_found','Rota não encontrada.'),
            };
            return new Response(200,['data'=>$data]);
        } catch (HttpError $e) { return new Response($e->status,['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]); }
    }
    private function slug(string $slug): string {
        if ($slug==='' || mb_strlen($slug)>250 || preg_match('~[/\\\\\x00-\x1f\x7f?#]~',$slug)) throw new HttpError(422,'invalid_slug','Endereço inválido.'); return $slug;
    }
    private function view(string $slug): array {
        $slug=$this->slug($slug);$user=$this->accounts->current(true);$p=$this->pages->source($slug);
        if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
        $level=max((int)$user['NIVEL'],(int)$this->config['public_max_level']);
        if ((int)($p['NIVEL'] ?? 1)>$level || str_starts_with(strtolower($p['IDENT']),'page@')) throw new HttpError(403,'permission_denied','Você não tem permissão para esta página.');
        $repo=new LegacyPageRepository($this->db->pdo,$level,$this->config);
        $row=$repo->find($slug) ?? ['id'=>$p['IDENT'],'slug'=>$p['NOME_URL'],'title'=>$p['NOME'],'html'=>$p['CONTEUDO'],'text'=>$p['CONTEUDO_TXT'],'updated_at'=>$p['DT_ATUALIZACAO']];
        return (new Application(static fn()=>$repo,$this->config))->present($row)+['is_public'=>$p['BUSCA']==='S' && (int)($p['NIVEL'] ?? 1)<=(int)$this->config['public_max_level']];
    }
    private function pageAction(string $method,string $slug,string $action,array $query,array $body): array {
        $slug=$this->slug($slug);
        if ($method==='GET' && $action==='editor') return $this->pages->editor($slug);
        if ($method==='GET' && $action==='history') return $this->pages->history($slug,$this->integer($query['before'] ?? PHP_INT_MAX,1,PHP_INT_MAX));
        if ($method==='POST' && $action==='preview') return $this->pages->preview($slug,$body);
        if ($method==='POST' && $action==='rebuild') return $this->pages->rebuild($slug);
        if ($method==='POST' && $action==='restore') {
            $p=$this->pages->source($slug);$this->pages->permit($p);if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
            $r=$this->db->one('SELECT NOME,CONTEUDO_CODE FROM PAGINAS_LOGS WHERE IDENT = ? AND NOME_URL_ID = ?',[$p['IDENT'],$this->integer($body['revision'] ?? 0,1,PHP_INT_MAX)]);
            if (!$r) throw new HttpError(404,'not_found','Revisão não encontrada.');
            return $this->pages->save($slug,['title'=>$r['NOME'],'source'=>$r['CONTEUDO_CODE'],'base_version'=>$body['base_version'] ?? null],true);
        }
        throw new HttpError(405,'method_not_allowed','Método não permitido.');
    }
    private function integer(mixed $value,int $min,int $max): int { $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if ($n===false) throw new HttpError(422,'invalid_pagination','Paginação inválida.');return $n; }
    private function bounds(array $q): array {return [$this->integer($q['limit'] ?? 50,1,100),$this->integer($q['offset'] ?? 0,0,1000000)];}
    private function publicFilter(string $alias='p'): string {return "$alias.BUSCA = 'S' AND COALESCE($alias.NIVEL,1) <= ".(int)$this->config['public_max_level']." AND $alias.IDENT NOT LIKE 'page@%'";}
    private function search(array $q): array {
        [$limit,$offset]=$this->bounds($q);$term=$q['q'] ?? '';
        if (!is_string($term) || mb_strlen($term)>100) throw new HttpError(422,'invalid_search','Busca inválida.');
        if (trim($term)==='') return ['items'=>[],'next_offset'=>null];
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($term)).'%';
        $rows=$this->db->rows("SELECT p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title,p.CONTEUDO_TXT AS description FROM PAGINAS p WHERE ".$this->publicFilter()." AND (p.NOME LIKE ? ESCAPE '!' OR p.CONTEUDO_TXT LIKE ? ESCAPE '!') ORDER BY p.NOME,p.IDENT LIMIT ? OFFSET ?",[$like,$like,$limit+1,$offset]);
        foreach($rows as &$row) $row['description']=mb_substr($row['description'] ?? '',0,240);
        return ['items'=>array_slice($rows,0,$limit),'next_offset'=>count($rows)>$limit?$offset+$limit:null];
    }
    private function catalog(array $q): array {
        [$limit,$offset]=$this->bounds($q);$type=Database::id(is_string($q['type'] ?? null)?$q['type']:'paginas');$filter=$this->publicFilter();$params=[];
        $sql=match($type) {
            'categorias' => "SELECT c.CATEGORIA_IDENT AS id,c.CATEGORIA_URL AS slug,MIN(c.CATEGORIA) AS title,COUNT(*) AS count FROM PAGINAS_CATEGORIAS c JOIN PAGINAS p ON p.IDENT=c.IDENT WHERE $filter GROUP BY c.CATEGORIA_IDENT,c.CATEGORIA_URL ORDER BY title",
            'links_inexistentes' => "SELECT DISTINCT l.LINK_IDENT AS id,l.LINK_URL AS slug,l.LINK AS title FROM PAGINAS_LINKS l JOIN PAGINAS p ON p.IDENT=l.IDENT LEFT JOIN PAGINAS target ON target.IDENT=l.LINK_IDENT WHERE $filter AND target.IDENT IS NULL AND l.LINK_URL IS NOT NULL AND l.LINK_IDENT NOT LIKE '#%' ORDER BY title",
            'redirecionamentos' => "SELECT r.IDENT AS id,r.NOME_URL AS slug,p.NOME AS title,r.PAGINA_URL AS destination FROM PAGINAS_REDIRECIONAMENTOS r JOIN PAGINAS p ON p.IDENT=r.IDENT WHERE $filter ORDER BY p.NOME",
            'paginas_sem_categoria' => "SELECT p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title FROM PAGINAS p WHERE $filter AND NOT EXISTS (SELECT 1 FROM PAGINAS_CATEGORIAS c WHERE c.IDENT=p.IDENT OR c.CATEGORIA_IDENT=p.IDENT) ORDER BY p.NOME",
            'videos' => "SELECT p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title,v.URL AS url FROM PAGINAS_VIDEOS v JOIN PAGINAS p ON p.IDENT=v.IDENT WHERE $filter ORDER BY p.NOME",
            'imagens_inexistentes' => "SELECT DISTINCT i.IMAGEM_IDENT AS id,i.IMAGEM_URL AS slug,i.IMAGEM AS title FROM PAGINAS_IMAGENS i JOIN PAGINAS p ON p.IDENT=i.IDENT WHERE $filter AND COALESCE(i.IMAGEM_FILE_URL,'') = '' ORDER BY title",
            'imagens_sem_pagina' => "SELECT p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title FROM PAGINAS p WHERE $filter AND p.IDENT LIKE 'imagem@%' AND NOT EXISTS (SELECT 1 FROM PAGINAS_IMAGENS i WHERE i.IMAGEM_IDENT=SUBSTR(p.IDENT,8)) ORDER BY p.NOME",
            'paginas','codigos','documentacao','listas','imagens','atualizar' => '',
            default => throw new HttpError(404,'catalog_not_found','Lista não encontrada.'),
        };
        if ($sql==='') {
            $extra=match($type) {'codigos','documentacao'=>"p.IDENT LIKE 'doc@%'",'listas'=>"p.IDENT LIKE '@%'",'imagens'=>"p.IDENT LIKE 'imagem@%'",'atualizar'=>"p.ATUALIZAR = 'S'",default=>"p.IDENT NOT LIKE '%@%'"};
            if ($type==='atualizar') { $u=$this->accounts->current(true);if ((int)$u['NIVEL']<5) throw new HttpError(403,'permission_denied','Acesso restrito a administradores.'); }
            $sql="SELECT p.IDENT AS id,p.NOME_URL AS slug,p.NOME AS title FROM PAGINAS p WHERE $filter AND $extra ORDER BY p.NOME";
        }
        $rows=$this->db->rows($sql.' LIMIT ? OFFSET ?',[$limit+1,$offset]);
        if ($type==='imagens_inexistentes') foreach($rows as &$row) $row['slug']='imagem@'.$row['slug'];
        return ['type'=>$type,'items'=>array_slice($rows,0,$limit),'next_offset'=>count($rows)>$limit?$offset+$limit:null];
    }
    private function statistics(): array {
        $filter=$this->publicFilter();
        $queries=[
            'pages'=>"SELECT COUNT(*) AS n FROM PAGINAS p WHERE $filter AND p.IDENT NOT LIKE 'imagem@%'",
            'images'=>"SELECT COUNT(*) AS n FROM PAGINAS p WHERE $filter AND p.IDENT LIKE 'imagem@%'",
            'categories'=>"SELECT COUNT(DISTINCT c.CATEGORIA_IDENT) AS n FROM PAGINAS_CATEGORIAS c JOIN PAGINAS p ON p.IDENT=c.IDENT WHERE $filter",
            'redirects'=>"SELECT COUNT(DISTINCT p.IDENT) AS n FROM PAGINAS p JOIN PAGINAS_REDIRECIONAMENTOS r ON r.IDENT=p.IDENT WHERE $filter",
            'uncategorized'=>"SELECT COUNT(*) AS n FROM PAGINAS p WHERE $filter AND NOT EXISTS (SELECT 1 FROM PAGINAS_CATEGORIAS c WHERE c.IDENT=p.IDENT OR c.CATEGORIA_IDENT=p.IDENT)",
            'unused_images'=>"SELECT COUNT(*) AS n FROM PAGINAS p WHERE $filter AND p.IDENT LIKE 'imagem@%' AND NOT EXISTS (SELECT 1 FROM PAGINAS_IMAGENS i WHERE i.IMAGEM_IDENT=SUBSTR(p.IDENT,8))",
            'missing_images'=>"SELECT COUNT(DISTINCT i.IMAGEM_IDENT) AS n FROM PAGINAS_IMAGENS i JOIN PAGINAS p ON p.IDENT=i.IDENT WHERE $filter AND COALESCE(i.IMAGEM_FILE_URL,'')=''",
            'videos'=>"SELECT COUNT(DISTINCT v.URL) AS n FROM PAGINAS_VIDEOS v JOIN PAGINAS p ON p.IDENT=v.IDENT WHERE $filter",
            'documentation'=>"SELECT COUNT(*) AS n FROM PAGINAS p WHERE $filter AND p.IDENT LIKE 'doc@%'",
            'missing_links'=>"SELECT COUNT(DISTINCT l.LINK_IDENT) AS n FROM PAGINAS_LINKS l JOIN PAGINAS p ON p.IDENT=l.IDENT LEFT JOIN PAGINAS target ON target.IDENT=l.LINK_IDENT WHERE $filter AND target.IDENT IS NULL AND l.LINK_IDENT NOT LIKE '#%'",
            'views'=>"SELECT COALESCE(SUM(p.ACESSOS),0) AS n FROM PAGINAS p WHERE $filter",
        ];$result=[];foreach($queries as $key=>$sql) $result[$key]=(int)$this->db->one($sql)['n'];return $result;
    }
    private function home(): array {
        return ['statistics'=>$this->statistics()] + (new Home($this->db,$this->renderer,$this->publicFilter()))->get();
    }
    private function visit(array $body): array {
        $slug=$this->slug(is_string($body['slug'] ?? null)?$body['slug']:'');$filter=$this->publicFilter();
        $p=$this->db->one("SELECT p.IDENT,p.NOME_URL FROM PAGINAS p WHERE $filter AND p.NOME_URL = ?",[$slug]);
        if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
        if (($this->session->data['visits'][$p['IDENT']] ?? 0)>time()-30) return ['counted'=>false];
        $date=date('Y-m-d');$now=date('Y-m-d H:i:s');
        $update='UPDATE PAGINAS_ACESSOS_LOGS_QTD_DIARIO SET QTD_ACESSOS = COALESCE(QTD_ACESSOS,0)+1, ULT_ATUALIZACAO = ? WHERE DATA = ? AND NOME_URL = ?';
        if ($this->db->run($update,[$now,$date,$slug])->rowCount()===0) {
            try { $this->db->run('INSERT INTO PAGINAS_ACESSOS_LOGS_QTD_DIARIO (DATA,IDENT,NOME_URL,QTD_ACESSOS,ULT_ATUALIZACAO) VALUES (?,?,?,1,?)',[$date,$p['IDENT'],$slug,$now]); }
            catch(\PDOException $e) { if ((string)$e->getCode()!=='23000') throw $e;$this->db->run($update,[$now,$date,$slug]); }
        }
        $this->db->run('UPDATE PAGINAS SET ACESSOS = COALESCE(ACESSOS,0)+1 WHERE IDENT = ?',[$p['IDENT']]);
        $this->session->data['visits'][$p['IDENT']]=time();return ['counted'=>true];
    }
    private function users(array $q,?int $id): array {
        $u=$this->accounts->current(true);if ((int)$u['NIVEL']!==5) throw new HttpError(403,'permission_denied','Acesso restrito a administradores.');
        if ($id!==null) { $row=$this->db->one('SELECT * FROM USUARIOS WHERE ID = ?',[$id]);if (!$row) throw new HttpError(404,'not_found','Usuário não encontrado.');return $this->accounts->profile($row); }
        [$limit,$offset]=$this->bounds($q);$filters=[];$values=[];
        foreach (['NOME','APELIDO','EMAIL','CIDADE','ESTADO','CLUBE','ASSOCIACAO','TIPO_CLUBE','ATIVO'] as $field) if (isset($q[$field]) && $q[$field]!=='') {
            if (!is_string($q[$field]) || mb_strlen($q[$field])>150) throw new HttpError(422,'invalid_filter','Filtro inválido.');
            $filters[]="$field LIKE ? ESCAPE '!'";$values[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q[$field]).'%';
        }
        if (isset($q['HAS_PHOTO']) && in_array($q['HAS_PHOTO'],['S','N'],true)) $filters[]="COALESCE(FOTO,'') ".($q['HAS_PHOTO']==='S'?"<> ''":"= ''");
        $values[]=$limit+1;$values[]=$offset;
        $rows=$this->db->rows('SELECT * FROM USUARIOS'.($filters?' WHERE '.implode(' AND ',$filters):'').' ORDER BY ID DESC LIMIT ? OFFSET ?',$values);
        return ['items'=>array_map($this->accounts->profile(...),array_slice($rows,0,$limit)),'next_offset'=>count($rows)>$limit?$offset+$limit:null];
    }
    private function upload(array $body): array { $u=$this->accounts->current(true);if ((int)$u['NIVEL']<1) throw new HttpError(403,'permission_denied','Você não tem permissão para enviar imagens da wiki.');$this->rate->check('media-user:'.$u['ID'],20,3600);return (new Media($this->config))->upload($body); }
    private function photo(array $body): array { $u=$this->accounts->current(true);$file=(new Media($this->config))->upload($body,'users',true);$this->db->run('UPDATE USUARIOS SET FOTO = ?, FOTO_32X32 = ?, FOTO_64 = NULL, FOTO_32X32_64 = NULL WHERE ID = ?',[$file['filename'],$file['filename'],(int)$u['ID']]);$this->notifications->notify('USU_ALTERACAO',$u['EMAIL']);return $file; }
}

