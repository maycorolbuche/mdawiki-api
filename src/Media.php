<?php
declare(strict_types=1);
namespace MDAWiki;
final class Media
{
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
}
