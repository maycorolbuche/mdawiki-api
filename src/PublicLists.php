<?php
declare(strict_types=1);
namespace MDAWiki;
final class PublicLists
{
    public function __construct(private Database $db, private array $config) {}
    public function render(string $html): string {
        return preg_replace_callback('~{{{lista:([^|}]+)(?:\|[^{}]*)?}}}~iu',function($m) {
            $type=Database::id(trim($m[1]));$url=$this->config['frontend_url'].'/page@Listas?type='.rawurlencode($type);
            if ($type==='usuarios') return '<a href="'.$this->config['frontend_url'].'/page@Usuarios">Consultar usuários</a>';
            if ($this->config['normalized_editor'] ?? false) {
                return '<a href="'.WikiRenderer::e($url).'">Consultar lista completa</a>';
            }
            $features=new Features($this->db,new Session(),$this->config,static function(){});
            $response=$features->handle('GET','/v1/catalog',['type'=>$type,'limit'=>100],[],'','internal');
            if ($response->status!==200) return '<p>Lista indisponível.</p>';
            $list='<ul class="article-list">';
            foreach($response->body['data']['items'] as $item) $list.='<li><a href="'.WikiRenderer::e($this->config['frontend_url'].'/'.rawurlencode($item['slug']).'/').'">'.WikiRenderer::e($item['title']).'</a></li>';
            return $list.'</ul><a href="'.$url.'">Consultar lista completa</a>';
        },$html);
    }
}
