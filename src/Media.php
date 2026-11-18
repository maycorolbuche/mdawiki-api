<?php
declare(strict_types=1);
namespace MDAWiki;
final class Media
{
    public static function rewrite(string $value,array $config): string {
        $base=rtrim($config['media_public_url'],'/');
        $prefixes=['https://mda.wiki.br/site/','http://mda.wiki.br/site/','https://www.mda.wiki.br/site/','http://www.mda.wiki.br/site/','https://mda.wiki.br/','http://mda.wiki.br/',$config['frontend_url'].'/site/',$config['frontend_url'].'/','{{{url_diretorio}}}','{{{url_site}}}','/site/'];
        foreach ($prefixes as $prefix) foreach(['@imgs_wiki/'=>'wiki/','@imgs_user/'=>'users/','@imgs/'=>'assets/'] as $old=>$new) $value=str_replace($prefix.$old,$base.'/'.$new,$value);
        return str_replace(['{{{url_diretorio}}}','@imgs_wiki/','@imgs_user/','@imgs/'],[$base.'/',$base.'/wiki/',$base.'/users/',$base.'/assets/'],$value);
    }
    public function __construct(private array $config) {}
    public function upload(array $body, string $group='wiki'): array {
        $encoded=$body['data'] ?? null;
        if (!is_string($encoded) || strlen($encoded)>7000000 || !preg_match('~^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$~D',$encoded,$m)) throw new HttpError(422,'invalid_upload','Envie uma imagem PNG, JPEG, GIF ou WebP de até 5 MB.');
        $bytes=base64_decode($m[2],true); $info=$bytes!==false ? @getimagesizefromstring($bytes):false;
        $formats=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        if (!$info || !isset($formats[$info['mime']]) || strlen($bytes)>5000000 || $info[0]*$info[1]>20000000) throw new HttpError(422,'invalid_upload','Imagem inválida ou com dimensões excessivas.');
        $directory=$this->config['media_root'].'/'.$group;if (!is_dir($directory) && !mkdir($directory,0755,true)) throw new \RuntimeException('Cannot create media directory');
        $name=bin2hex(random_bytes(16)).'.'.$formats[$info['mime']];if (file_put_contents($directory.'/'.$name,$bytes,LOCK_EX)===false) throw new \RuntimeException('Cannot save image');
        return ['url'=>$this->config['media_public_url'].'/'.$group.'/'.$name,'filename'=>$name,'width'=>$info[0],'height'=>$info[1]];
    }
    public function serve(string $path): never {
        $root=realpath($this->config['media_root']);$decoded=rawurldecode(substr($path,7));
        $file=$root && !str_contains($decoded,"\0") ? realpath($root.'/'.$decoded):false;
        $types=['svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif'];
        $type=$file?$types[strtolower(pathinfo($file,PATHINFO_EXTENSION))] ?? null:null;
        if (!$file || !$root || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !$type || !is_file($file)) { http_response_code(404);exit; }
        header('Content-Type: '.$type);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");header('Cache-Control: public, max-age=86400');readfile($file);exit;
    }
    /** Only used to restore an already stored revision; normal uploads reject SVG. */
    public function restore(string $data): array {
        if (!str_starts_with($data,'data:image/svg+xml;base64,')) return $this->upload(['data'=>$data]);
        $bytes=base64_decode(substr($data,26),true);
        if (!$bytes || strlen($bytes)>5000000 || preg_match('/<!DOCTYPE|<!ENTITY/i',$bytes)) throw new HttpError(422,'invalid_svg','SVG de histórico inválido.');
        $doc=new \DOMDocument();if (!@$doc->loadXML($bytes,LIBXML_NONET) || $doc->documentElement->localName!=='svg') throw new HttpError(422,'invalid_svg','SVG de histórico inválido.');
        $xpath=new \DOMXPath($doc);
        foreach($xpath->query('//*[local-name()="script" or local-name()="foreignObject" or local-name()="iframe" or local-name()="object" or local-name()="embed"]') as $node) $node->parentNode->removeChild($node);
        foreach($xpath->query('//@*') as $attribute) { $name=strtolower($attribute->localName);$v=$attribute->value;
            if (str_starts_with($name,'on') || ($name==='href' && !str_starts_with($v,'#')) || ($name==='style' && preg_match('/url\((?![\'" ]*#)|expression|javascript/i',$v))) $attribute->ownerElement->removeAttributeNode($attribute);
        }
        $dir=$this->config['media_root'].'/wiki';if (!is_dir($dir)) mkdir($dir,0755,true);$name=bin2hex(random_bytes(16)).'.svg';
        if (file_put_contents($dir.'/'.$name,$doc->saveXML(),LOCK_EX)===false) throw new \RuntimeException('Cannot restore SVG');
        return ['url'=>$this->config['media_public_url'].'/wiki/'.$name,'filename'=>$name];
    }
}
