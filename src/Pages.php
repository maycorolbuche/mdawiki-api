<?php
declare(strict_types=1);
namespace MDAWiki;
final class Pages
{
    public function __construct(private Database $db, private Accounts $accounts, private WikiRenderer $renderer, private array $config, private ?WikiMail $notifications = null) {}
    public function source(string $slug): ?array { return $this->db->one('SELECT * FROM PAGINAS WHERE NOME_URL = ?',[$slug]); }
    public function permit(?array $page): array {
        $u=$this->accounts->current(true);
        if ((int)($page['NIVEL'] ?? 1)>(int)$u['NIVEL'] || ($page && str_starts_with(strtolower($page['IDENT']),'page@'))) throw new HttpError(403,'permission_denied','Você não tem permissão para esta página.');
        return $u;
    }
    public static function version(?array $page): string { return $page ? hash('sha256',json_encode([$page['CONTEUDO_CODE'],$page['NOME'],$page['DT_ALTERACAO']],JSON_UNESCAPED_UNICODE)) : ''; }
    public function editor(string $slug): array {
        $p=$this->source($slug); $this->permit($p);
        $source=$p['CONTEUDO_CODE'] ?? '';$media=str_starts_with(mb_strtolower($slug),'imagem@');
        if ($media && $p && !str_starts_with($source,'data:image/')) { $file=json_decode($p['CONTEUDO'] ?? '',true);if (!empty($file['url'])) { $source=$this->renderer->mediaUrl($file['url']);if (!preg_match('~^https?://~',$source)) $source=rtrim($this->config['media_public_url'],'/').'/'.ltrim($source,'/'); } }
        return ['slug'=>$slug,'title'=>$p['NOME'] ?? str_replace('_',' ',$slug),'source'=>$source, 'base_version'=>self::version($p),'format'=>($p['HTML'] ?? 'N')==='S'?'html':'wiki','exists'=>$p!==null,'media'=>$media];
    }
    public function history(string $slug, int $before=PHP_INT_MAX): array {
        $p=$this->source($slug);$this->permit($p);
        if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
        $rows=$this->db->rows('SELECT l.NOME_URL_ID AS id,l.NOME AS title,l.CONTEUDO_CODE AS source,l.DATAHORA AS created_at,u.APELIDO AS author,u.FOTO AS author_photo_original,u.FOTO_32X32 AS author_photo_thumbnail FROM PAGINAS_LOGS l LEFT JOIN USUARIOS u ON u.ID=l.ID_USUARIO WHERE l.IDENT = ? AND l.NOME_URL_ID < ? ORDER BY l.NOME_URL_ID DESC LIMIT 51',[$p['IDENT'],$before]);
        $more=count($rows)>50; $rows=array_slice($rows,0,50);
        foreach ($rows as &$row) {
            $row['author_photo']=Accounts::photoUrl(['FOTO'=>$row['author_photo_original'],'FOTO_32X32'=>$row['author_photo_thumbnail']],$this->config,true);
            unset($row['author_photo_original'],$row['author_photo_thumbnail']);
        }
        unset($row);
        return ['title'=>$p['NOME'],'revisions'=>$rows,'next_cursor'=>$more?(int)end($rows)['id']:null,'base_version'=>self::version($p)];
    }
    public function save(string $slug, array $body, bool $restore=false, bool $recover=false): array {
        $p=$this->source($slug);$u=$this->permit($p);
        if (!is_string($body['base_version'] ?? null) || !hash_equals(self::version($p),$body['base_version'])) throw new HttpError(409,'edit_conflict','Outra edição foi salva. Recarregue a página antes de salvar.');
        $title=trim(is_string($body['title'] ?? null)?$body['title']:'');$source=$body['source'] ?? null;
        if ($title==='' || mb_strlen($title)>250 || !is_string($source) || strlen($source)>(str_starts_with(mb_strtolower($slug),'imagem@')?7000000:500000)) throw new HttpError(422,'invalid_page','Título ou conteúdo inválido (artigos: 500 KB; imagens: 5 MB).');
        $id=$p['IDENT'] ?? Database::id($slug);
        if (!$p && $this->db->one('SELECT IDENT FROM PAGINAS WHERE IDENT = ?',[$id])) throw new HttpError(409,'slug_conflict','Já existe uma página com este identificador.');
        if (str_starts_with(strtolower($slug),'page@')) throw new HttpError(403,'system_page','Este endereço é reservado ao sistema.');
        $lockDir=$this->config['storage_dir'].'/locks'; if (!is_dir($lockDir)) @mkdir($lockDir,0700,true);
        $lock=fopen($lockDir.'/'.hash('sha256',$id).'.lock','c');if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock article');
        try {
            $latest=$this->source($slug);
            if (!hash_equals(self::version($latest),$body['base_version'])) throw new HttpError(409,'edit_conflict','Outra edição foi salva. Recarregue antes de salvar.');
            $journalDir=$this->config['storage_dir'].'/pending';
            $journal=$journalDir.'/'.hash('sha256',$id).'.json';
            if (is_file($journal) && !$recover) throw new HttpError(503,'pending_recovery','Esta página tem uma gravação pendente de recuperação pelo administrador.');
            if (!$recover && $latest && $title===$latest['NOME'] && $source===($latest['CONTEUDO_CODE'] ?? '')) return $this->unchanged($slug,$latest);
            $imageProcessor=null;$imageFiles=[];
            if (str_starts_with(strtolower($id),'imagem@')) {
                $imageProcessor=new LegacyImage($this->config);
                $image=$imageProcessor->render($id,$source,$restore);
                $source=$image['source'];$imageFiles=$image['files'];
                if (!$recover && $latest && $title===$latest['NOME'] && $source===($latest['CONTEUDO_CODE'] ?? '')) return $this->unchanged($slug,$latest);
                $rendered=['html'=>$image['html'],'text'=>$image['text'],'categories'=>[],'links'=>[],'external_links'=>[],'templates'=>[],'includes'=>[],'images'=>[],'videos'=>[],'headings'=>[],'redirect'=>null];
            } else $rendered=$this->renderer->render($source,$slug,($p['HTML'] ?? 'N')==='S');
            $now=date('Y-m-d H:i:s');
            $revision=(int)($this->db->one('SELECT MAX(NOME_URL_ID) AS n FROM PAGINAS_LOGS WHERE IDENT = ?',[$id])['n'] ?? 0)+1;
            // Persist the recovery source before changing the MyISAM cache.
            // Rebuild can safely replay the latest revision after an interrupted write.
            if (!is_dir($journalDir)) @mkdir($journalDir,0700,true);
            if (file_put_contents($journal,json_encode(['slug'=>$slug,'title'=>$title,'source'=>$source,'user_id'=>$u['ID'],'revision'=>$revision,'time'=>$now],JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new \RuntimeException('Cannot write recovery journal');
            if ($imageProcessor) $imageProcessor->publish($imageFiles);
            $this->db->run('INSERT INTO PAGINAS_LOGS (IDENT,NOME_URL_ID,NOME_URL,NOME,CONTEUDO_CODE,CONTEUDO,EMAIL,ID_USUARIO,DATAHORA) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$revision,$slug,$title,$source,$rendered['html'],$u['EMAIL'],(int)$u['ID'],$now]);
            if (!$p) $this->db->run("INSERT INTO PAGINAS (IDENT,NOME_URL,NOME,CONTEUDO_CODE,CONTEUDO,CONTEUDO_TXT,USU_CADASTRO,DT_CADASTRO,USU_ALTERACAO,DT_ALTERACAO,DT_ATUALIZACAO,BUSCA,NIVEL,ATUALIZAR,HTML) VALUES (?,?,?,?,?,?,?,?,?,?,?,'S',1,'N','N')",[$id,$slug,$title,$source,$rendered['html'],$rendered['text'],$u['EMAIL'],$now,$u['EMAIL'],$now,$now]);
            else $this->db->run("UPDATE PAGINAS SET NOME = ?, CONTEUDO_CODE = ?, CONTEUDO = ?, CONTEUDO_TXT = ?, USU_ALTERACAO = ?, DT_ALTERACAO = ?, DT_ATUALIZACAO = ?, ATUALIZAR = 'N' WHERE IDENT = ?",[$title,$source,$rendered['html'],$rendered['text'],$u['EMAIL'],$now,$now,$id]);
            $this->relations($id,$slug,$source,$rendered);
            $this->db->run("UPDATE PAGINAS SET ATUALIZAR = 'S' WHERE IDENT IN (SELECT IDENT FROM PAGINAS_INCLUDES WHERE PAGINA_IDENT = ?)",[$id]);
            if (str_starts_with($id,'imagem@')) $this->db->run("UPDATE PAGINAS SET ATUALIZAR = 'S' WHERE IDENT IN (SELECT IDENT FROM PAGINAS_IMAGENS WHERE IMAGEM_IDENT = ?)",[substr($id,7)]);
            if ($imageProcessor) $imageProcessor->cleanup($p['CONTEUDO'] ?? '',$rendered['html'],$id);
            unlink($journal);
            $action = $p ? 'update' : 'new';
            if ($imageProcessor) {
                $metadata = json_decode($rendered['html'], true);
                $cleared = $source === '' && !empty($p['CONTEUDO_CODE']);
                if ($source !== '' || $cleared) {
                    $imageUrl = isset($metadata['url']) ? str_replace('@imgs_wiki/', $this->config['media_public_url'] . '/wiki/', $metadata['url']) : null;
                    $this->notifications?->event('img', $cleared ? 'delete' : $action, $title, $u,
                        ['Arquivo' => $imageUrl, 'Endereço' => $slug], $imageUrl);
                }
            } else {
                $this->notifications?->event('page', $action, $title, $u, ['Endereço' => $slug, 'Conteúdo' => $source]);
            }
            return ['slug'=>$slug,'revision'=>$revision,'base_version'=>self::version($this->source($slug))];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    private function unchanged(string $slug,array $page): array {
        $revision=(int)($this->db->one('SELECT MAX(NOME_URL_ID) AS n FROM PAGINAS_LOGS WHERE IDENT = ?',[$page['IDENT']])['n'] ?? 0);
        return ['slug'=>$slug,'revision'=>$revision,'base_version'=>self::version($page),'unchanged'=>true];
    }
    public function preview(string $slug,array $body): array {
        $p=$this->source($slug);$this->permit($p);
        if (!is_string($body['source'] ?? null) || strlen($body['source'])>500000) throw new HttpError(422,'invalid_page','Conteúdo inválido.');
        return ['html'=>$this->renderer->render($body['source'],$slug,($p['HTML'] ?? 'N')==='S')['html']];
    }
    public function rebuild(string $slug): array {
        $u=$this->accounts->current(true);if ((int)$u['NIVEL']<5) throw new HttpError(403,'permission_denied','Acesso restrito a administradores.');
        return $this->rebuildCache($slug);
    }
    /** Called by the local maintenance command; never routed directly over HTTP. */
    public function rebuildCache(string $slug): array {
        $p=$this->source($slug);if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
        $directory=$this->config['storage_dir'].'/locks';if (!is_dir($directory)) @mkdir($directory,0700,true);
        $lock=fopen($directory.'/'.hash('sha256',$p['IDENT']).'.lock','c');if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock article rebuild');
        try {
        $p=$this->source($slug);if (!$p) throw new HttpError(404,'not_found','Página não encontrada.');
        if (is_file($this->config['storage_dir'].'/pending/'.hash('sha256',$p['IDENT']).'.json')) throw new HttpError(503,'pending_recovery','Recupere a gravação pendente antes de reprocessar.');
        if (str_starts_with($p['IDENT'],'page@')) throw new HttpError(422,'system_page','Página de sistema não pode ser processada como artigo.');
        if (str_starts_with($p['IDENT'],'imagem@')) {
            $images=new LegacyImage($this->config);$image=$images->render($p['IDENT'],$p['CONTEUDO_CODE'] ?? '');$images->publish($image['files']);
            $this->db->run("UPDATE PAGINAS SET CONTEUDO_CODE = ?,CONTEUDO = ?,CONTEUDO_TXT = ?,DT_ATUALIZACAO = ?,ATUALIZAR = 'N' WHERE IDENT = ?",[$image['source'],$image['html'],$image['text'],date('Y-m-d H:i:s'),$p['IDENT']]);
            $images->cleanup($p['CONTEUDO'] ?? '',$image['html'],$p['IDENT']);return ['message'=>'Imagem disponível.'];
        }
        $result=$this->renderer->render($p['CONTEUDO_CODE'] ?? '',$slug,$p['HTML']==='S');
        $this->db->run("UPDATE PAGINAS SET CONTEUDO = ?, CONTEUDO_TXT = ?, DT_ATUALIZACAO = ?, ATUALIZAR = 'N' WHERE IDENT = ?",[$result['html'],$result['text'],date('Y-m-d H:i:s'),$p['IDENT']]);
        $this->relations($p['IDENT'],$slug,$p['CONTEUDO_CODE'],$result);
        if ($p['CONTEUDO']!==$result['html']) $this->db->run("UPDATE PAGINAS SET ATUALIZAR = 'S' WHERE IDENT IN (SELECT IDENT FROM PAGINAS_INCLUDES WHERE PAGINA_IDENT = ?)",[$p['IDENT']]);
        return ['message'=>'Página reprocessada.'];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function relations(string $id,string $slug,string $source,array $r): void {
        foreach (['PAGINAS_CATEGORIAS','PAGINAS_LINKS','PAGINAS_LINKS_EXTERNOS','PAGINAS_REDIRECIONAMENTOS','PAGINAS_INCLUDES','PAGINAS_TEMPLATES','PAGINAS_SUBTITULOS','PAGINAS_IMAGENS','PAGINAS_VIDEOS'] as $table) $this->db->run("DELETE FROM $table WHERE IDENT = ?",[$id]);
        $i=0;foreach($r['categories'] as $c) $this->db->run('INSERT INTO PAGINAS_CATEGORIAS (IDENT,NOME_URL,CATEGORIA,CATEGORIA_IDENT,CATEGORIA_URL,ORDEM) VALUES (?,?,?,?,?,?)',[$id,$slug,$c['title'],$c['id'],$c['slug'],++$i]);
        foreach($r['links'] as $i=>$l) $this->db->run('INSERT INTO PAGINAS_LINKS (IDENT,NOME_URL,ID,LINK,LINK_IDENT,LINK_URL,LINK_HASH,LINK_TXT) VALUES (?,?,?,?,?,?,?,?)',[$id,$slug,$i+1,str_replace('_',' ',$l['slug']),$l['id'],$l['slug'],$l['hash'],$l['title']]);
        foreach($r['external_links'] as $i=>$l) $this->db->run('INSERT INTO PAGINAS_LINKS_EXTERNOS (IDENT,NOME_URL,ID,LINK_URL,LINK_TXT) VALUES (?,?,?,?,?)',[$id,$slug,$i+1,$l['url'],$l['title']]);
        if ($r['redirect']) $this->db->run('INSERT INTO PAGINAS_REDIRECIONAMENTOS (IDENT,NOME_URL,ID,PAGINA,PAGINA_IDENT,PAGINA_URL) VALUES (?,?,1,?,?,?)',[$id,$slug,str_replace('_',' ',$r['redirect']),Database::id($r['redirect']),$r['redirect']]);
        preg_match_all('~{{Incluir:([^{}]+)}}~iu',$source,$inc);
        foreach($inc[1] as $i=>$v) {[$name,$section]=array_pad(explode('#',$v,2),2,'');$this->db->run('INSERT INTO PAGINAS_INCLUDES (IDENT,NOME_URL,ID,PAGINA,PAGINA_URL,PAGINA_IDENT,PAGINA_SEC) VALUES (?,?,?,?,?,?,?)',[$id,$slug,$i+1,str_replace('_',' ',$name),str_replace(' ','_',$name),Database::id($name),$section]);}
        foreach($r['templates'] as $i=>$v) $this->db->run('INSERT INTO PAGINAS_TEMPLATES (IDENT,NOME_URL,ID,TEMPLATE_TAG,TEMPLATE) VALUES (?,?,?,?,?)',[$id,$slug,$i+1,mb_substr('{{'.$v.'}}',0,250),mb_substr($v,0,250)]);
        foreach($r['headings'] as $i=>$v) $this->db->run('INSERT INTO PAGINAS_SUBTITULOS (IDENT,NOME_URL,ID,SUBTITULO,SUBTITULO_IDENT,SUBTITULO_URL,NIVEL) VALUES (?,?,?,?,?,?,?)',[$id,$slug,$i+1,$v['title'],$v['id'],$v['id'],$v['level']]);
        foreach($r['videos'] as $i=>$v) $this->db->run('INSERT INTO PAGINAS_VIDEOS (IDENT,NOME_URL,ID,URL,MSG_ERRO) VALUES (?,?,?,?,?)',[$id,$slug,$i+1,$v,'']);
        $refs=$r['image_refs'] ?? [];$known=array_column($refs,'url');
        foreach($r['images'] as $v) { if (in_array($v,$known,true) || str_contains($v,'img_nao_encontrada') || !str_starts_with($v,$this->config['media_public_url'].'/wiki/')) continue;$name=preg_replace('/^imagem@/','',pathinfo(parse_url($v,PHP_URL_PATH) ?: $v,PATHINFO_FILENAME));$refs[]=['name'=>$name,'id'=>Database::id($name),'url'=>$v,'template'=>'N']; }
        foreach($refs as $i=>$v) $this->db->run('INSERT INTO PAGINAS_IMAGENS (IDENT,NOME_URL,ID,IMAGEM_IDENT,IMAGEM,IMAGEM_URL,IMAGEM_FILE_URL,IMAGEM_FILE_MINI_URL,IMAGEM_FILE_CP_URL,TEMPLATE) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$slug,$i+1,$v['id'],$v['name'],str_replace(' ','_',$v['name']),$v['url'],$v['url'],$v['url'],$v['template']]);
    }
}

