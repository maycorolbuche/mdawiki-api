<?php
declare(strict_types=1);
namespace MDAWiki;
final class WikiRenderer
{
    public function __construct(private Database $db, private array $config) { require_once __DIR__.'/wiki-presentation.php'; }
    public static function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public function mediaUrl(string $value): string {
        return Media::rewrite($value,$this->config);
    }
    public function urls(string $html): string {
        $html=$this->mediaUrl($html);
        $html=str_replace('{{{url_site}}}',rtrim($this->config['frontend_url'],'/').'/',$html);
        return preg_replace_callback('~(href=[\'"])(?:https?://mda\.wiki\.br)/([^\'"<>]*)~i',fn($m)=>$m[1].rtrim($this->config['frontend_url'],'/').'/'.$m[2],$html);
    }
    public function image(string $name): array {
        if ($this->config['normalized_editor'] ?? false) {
            $page = NormalizedPageRepository::lookup($this->db, 'imagem@' . preg_replace('/!tmpl$/', '', $name));
            if (!$page || $page['kind'] !== 'image' || !$page['is_searchable']
                || (int) ($page['access_level_id'] ?? 1) > (int) $this->config['public_max_level']) {
                return [];
            }
            $file = $this->db->one('SELECT f.storage_key FROM images i JOIN files f ON f.id = i.file_id WHERE i.page_id = ?', [$page['id']]);
            return $file ? ['IMAGEM' => json_encode(array_fill_keys(['url', 'url_mini', 'url_cp'], $file['storage_key']), JSON_THROW_ON_ERROR)] : [];
        }
        $row=$this->db->one("SELECT CONTEUDO FROM PAGINAS WHERE IDENT = ? AND COALESCE(NIVEL,1) <= ?",['imagem@'.Database::id(preg_replace('/!tmpl$/','',$name)),(int)$this->config['public_max_level']]);
        $file=json_decode($row['CONTEUDO'] ?? '',true);
        if (!is_array($file) || empty($file['url'])) return [];
        foreach (['url','url_mini','url_cp'] as $key) if (!empty($file[$key])) { $file[$key]=$this->mediaUrl($file[$key]);$base=rtrim($this->config['media_public_url'],'/').'/';if (str_starts_with($file[$key],$base)) $file[$key]=substr($file[$key],strlen($base)); }
        return ['IMAGEM'=>json_encode($file,JSON_UNESCAPED_UNICODE)];
    }
    public function includes(string $source, array $stack=[]): string {
        if (count($stack)>8) throw new HttpError(422,'include_depth','Limite de páginas incluídas excedido.');
        return preg_replace_callback('~{{Incluir:([^{}]+)}}~iu',function($m) use($stack) {
            [$name,$section]=array_pad(explode('#',trim($m[1]),2),2,''); $id=Database::id($name);
            if (in_array($id,$stack,true)) throw new HttpError(422,'include_cycle','Inclusão circular: '.$name);
            if ($this->config['normalized_editor'] ?? false) {
                $row = NormalizedPageRepository::lookup($this->db, $name);
                $p = $row && $row['is_searchable'] && (int) ($row['access_level_id'] ?? 1) <= (int) $this->config['public_max_level']
                    && !str_starts_with($row['legacy_identifier'], 'page@') ? ['CONTEUDO_CODE' => $row['source']] : null;
            } else {
                $p=$this->db->one("SELECT CONTEUDO_CODE FROM PAGINAS WHERE IDENT = ? AND BUSCA = 'S' AND COALESCE(NIVEL,1) <= ? AND IDENT NOT LIKE 'page@%'",[$id,(int)$this->config['public_max_level']]);
            }
            if (!$p) return '<strong>Página incluída não encontrada: '.self::e($name).'</strong>';
            $text=$p['CONTEUDO_CODE'] ?? '';
            if ($section==='!') $text=explode('==',$text,2)[0];
            elseif ($section!=='') { $parts=explode('=='.$section.'==',$text,2); $text=explode('==',$parts[1] ?? '',2)[0]; }
            return $this->includes($text,[...$stack,$id]);
        },$source);
    }
    public function render(string $source, string $slug, bool $rawHtml=false): array {
        $source=str_replace(["\r\n","\r"],"\n",$source);
        $source=$this->includes($source,[Database::id($slug)]);
        $meta=['categories'=>[],'links'=>[],'external_links'=>[],'includes'=>[],'templates'=>[],'images'=>[],'videos'=>[],'headings'=>[],'redirect'=>null];
        preg_match_all('~{{Categorias?:([^{}]+)}}~iu',$source,$categories);
        foreach ($categories[1] as $values) foreach(explode(',',$values) as $value) { $value=trim($value); if ($value!=='') $meta['categories'][Database::id($value)]=['id'=>Database::id($value),'slug'=>str_replace(' ','_',$value),'title'=>str_replace('_',' ',$value)]; }
        preg_match_all('~{{([^{}]+)}}~u',$source,$templates); $meta['templates']=$templates[1];
        preg_match_all('~{{Video:([^|}]+)~iu',$source,$videos); $meta['videos']=$videos[1];
        if (preg_match('~{{{Redirecionar:([^{}]+)}}}~iu',$source,$redirect)) $meta['redirect']=str_replace(' ','_',trim($redirect[1]));
        $html=preg_replace('~{{Categorias?:[^{}]+}}|{{{Redirecionar:[^{}]+}}}~iu','',$source);
        $html=preg_replace('~/\*.*?\*/~s','',$html);
        // Existing TAGS rules remain data, never PHP/eval. Invalid rules fail saves.
        foreach (['PRE','POS'] as $position) {
            if ($position==='POS' && !$rawHtml) {
                $GLOBALS['mdawiki_image_resolver']=function($name) use (&$meta) {
                    $result=$this->image($name);$file=json_decode($result['IMAGEM'] ?? '',true) ?: [];
                    $title=preg_replace('/!tmpl$/','',$name);$url=$file['url'] ?? '';
                    if ($url!=='' && !preg_match('~^https?://~',$url)) $url=rtrim($this->config['media_public_url'],'/').'/'.ltrim($url,'/');
                    $meta['image_refs'][]=['name'=>$title,'id'=>Database::id($title),'url'=>$url,'template'=>str_ends_with($name,'!tmpl')?'S':'N'];
                    return $result;
                };
                $html=\MDAWiki\Wiki\page_tabelas($html);
                $html=\MDAWiki\Wiki\page_templates($html,'');
                $html=\MDAWiki\Wiki\page_lista_marcadores($html);
                $html=preg_replace_callback('~(={2,6})([^=\n]+)\1~u',function($m) use(&$meta) { $level=min(6,strlen($m[1])); $title=trim($m[2]);$id=Database::id(strip_tags($title));$meta['headings'][]=['title'=>strip_tags($title),'id'=>$id,'level'=>$level];return '<h'.$level.' id="'.self::e($id).'">'.$title.'</h'.$level.'>'; },$html);
                $html=preg_replace("/'''(.*?)'''/s",'<strong>$1</strong>',$html);
                $html=preg_replace("/''(.*?)''/s",'<em>$1</em>',$html);
                $html=preg_replace_callback('~\[codigo\](.*?)\[/codigo\]~si',fn($m)=>'<pre><code>'.self::e($m[1]).'</code></pre>',$html);
                $html=preg_replace_callback('~\[\[([^\]]+)\]\]~u',function($m) use(&$meta,$slug) {
                    [$target,$title]=array_pad(explode('|',$m[1],2),2,'');$target=trim($target);$title=$title!==''?$title:$target;
                    if (preg_match('~^https?://~i',$target)) { $meta['external_links'][]=['url'=>$target,'title'=>strip_tags($title)]; return '<a href="'.self::e($target).'" target="_blank" rel="noopener noreferrer">'.$title.'</a>'; }
                    [$name,$hash]=array_pad(explode('#',$target,2),2,'');$name=str_replace(' ','_',$name);$name=$name===''?$slug:$name;
                    $meta['links'][]=['id'=>Database::id($name),'slug'=>$name,'title'=>strip_tags($title),'hash'=>$hash];
                    return '<a href="'.self::e($this->config['frontend_url'].'/'.rawurlencode($name).'/'.($hash!==''?'#'.rawurlencode(Database::id($hash)):'' )).'">'.$title.'</a>';
                },$html);
                $html=str_replace(['{{Imagens}}','{{/Imagens}}'],['<div class="wiki-gallery">','</div>'],$html);
            }
            $sql = ($this->config['normalized_editor'] ?? false)
                ? 'SELECT rule_key AS ID, source_pattern AS PARAM_CODE, html_pattern AS PARAM_HTML
                    FROM wiki_markup_rules WHERE scope = ? AND handler = ? ORDER BY position, rule_key'
                : 'SELECT ID,PARAM_CODE,PARAM_HTML FROM TAGS WHERE POSICAO = ? AND FUNCAO = ? ORDER BY ORDEM';
            foreach ($this->db->rows($sql, [$position, 'preg_replace']) as $rule) {
                $invalidRule='Regra inválida na tabela TAGS (ID: '.($rule['ID'] ?? '?').'; posição: '.$position.').';
                $pattern=$rule['PARAM_CODE'];
                if (!is_string($pattern) || trim($pattern)==='') throw new HttpError(422,'invalid_wiki_rule',$invalidRule.' Expressão regular ausente.');
                // Legacy nvl(PARAM_HTML) treats SQL NULL as an empty replacement.
                $next=@preg_replace($pattern,$rule['PARAM_HTML'] ?? '',$html);
                if ($next===null) throw new HttpError(422,'invalid_wiki_rule',$invalidRule); $html=$next;
            }
        }
        $html=$this->urls($html);
        preg_match_all('~<img[^>]+src=[\'"]([^\'"<>]+)[\'"]~i',$html,$images); $meta['images']=$images[1];
        // Dynamic list macros are links to the new live catalog, never old PHP.
        $html=(new PublicLists($this->db,$this->config))->render($html);
        return $meta+['html'=>$html,'text'=>trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))];
    }
}

