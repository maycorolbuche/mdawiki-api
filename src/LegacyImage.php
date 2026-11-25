<?php
declare(strict_types=1);
namespace MDAWiki;

/** The field layout and derived images produced by the legacy geraImagem routine. */
final class LegacyImage
{
    public function __construct(private array $config) {}

    public function render(string $id, string $source, bool $restore = false): array
    {
        if ($source === '') return ['source'=>'','html'=>'','text'=>'','files'=>[]];
        if (strpbrk($id,"/\\\0") !== false || strlen($id)>250) throw new HttpError(422,'invalid_media','Identificador de imagem inválido.');
        // Compatibility with uploads already submitted as a URL by the previous front.
        if (!str_starts_with($source,'data:image/')) {
            $path = $this->storageKey($source);
            $bytes = $path === null ? null : (new MediaStorage($this->config))->read($path);
            if ($bytes === null) throw new HttpError(422,'media_not_found','Imagem não encontrada no armazenamento da API.');
            if (strlen($bytes)>5000000) throw new HttpError(422,'invalid_upload','Envie uma imagem de até 5 MB.');
            $mime = strtolower(pathinfo($path,PATHINFO_EXTENSION))==='svg' ? 'image/svg+xml' : (@getimagesizefromstring($bytes)['mime'] ?? '');
            $source = 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        if (strlen($source)>7000000 || !preg_match('~^data:(image/(?:png|jpeg|gif|webp|svg\+xml));base64,([A-Za-z0-9+/=\r\n]+)$~D',$source,$match)) throw new HttpError(422,'invalid_upload','Imagem base64 inválida (máximo 5 MB).');
        $bytes = base64_decode($match[2],true);$mime = $match[1];
        if (!$bytes || strlen($bytes)>5000000) throw new HttpError(422,'invalid_upload','Imagem base64 inválida (máximo 5 MB).');
        $svg = $mime==='image/svg+xml';$image = null;$doc = null;
        if ($svg) {
            if (!$restore && preg_match('~<\s*(?:script|foreignObject|iframe|object|embed)\b|\bon[a-z]+\s*=|<!DOCTYPE|<!ENTITY~i',$bytes)) throw new HttpError(422,'invalid_svg','O SVG contém conteúdo ativo não permitido.');
            $doc = Media::svgDocument($bytes);
            $bytes=$doc->saveXML();$source='data:image/svg+xml;base64,'.base64_encode($bytes);
            $root = $doc->documentElement;
            $box = preg_split('/[\s,]+/',trim($root->getAttribute('viewBox')));
            $dimension = static fn($v,$fallback)=>preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/D',$v,$m)?(int)ceil((float)$m[1]):$fallback;
            $w = $dimension($root->getAttribute('width'),isset($box[2])?(int)ceil((float)$box[2]):300);
            $h = $dimension($root->getAttribute('height'),isset($box[3])?(int)ceil((float)$box[3]):150);
            $size = [0=>$w,1=>$h,2=>'',3=>"width='$w' height='$h'",'bits'=>'','mime'=>'svg+xml'];
            $type = 'svg';
        } else {
            $size = @getimagesizefromstring($bytes);
            if (!$size || $size['mime']!==$mime) throw new HttpError(422,'invalid_upload','Imagem inválida.');
            [$w,$h] = $size;
            if ($w<1 || $h<1 || $w*$h>20000000) throw new HttpError(422,'invalid_upload','Imagem com dimensões excessivas.');
            if (!function_exists('imagecreatefromstring')) throw new HttpError(503,'image_processing_unavailable','Habilite a extensão GD do PHP para processar imagens como no legado.');
            $image = @imagecreatefromstring($bytes);
            if (!$image) throw new HttpError(422,'invalid_upload','Não foi possível decodificar a imagem.');
            $type = substr($mime,6);
            $scale = min(500/$w,500/$h,1);
            if ($scale<1) {
                $scaled = $this->raster($image,$w,$h,max(1,(int)floor($w*$scale)),max(1,(int)floor($h*$scale)),$mime);
                imagedestroy($image);$bytes=$scaled;$source='data:'.$mime.';base64,'.base64_encode($bytes);
                $image=imagecreatefromstring($bytes);$size=getimagesizefromstring($bytes);[$w,$h]=$size;
            }
            $size[3] = str_replace('"',"'",$size[3]);
        }
        if ($w<1 || $h<1 || $w*$h>20000000) throw new HttpError(422,'invalid_upload','Imagem com dimensões inválidas.');
        $meta = ['tipo'=>$type,'size'=>$size,'w'=>$w,'h'=>$h];$files=[];
        foreach ([''=>['wiki','@imgs_wiki/',1],'_mini'=>['wiki_mini','@imgs_wiki_mini/',min(100/$w,100/$h,1)],'_cp'=>['wiki_cp','@imgs_wiki_cp/',max(min(200/$w,200/$h),1)]] as $suffix=>[$group,$prefix,$scale]) {
            $width=max(1,(int)floor($w*$scale));$height=max(1,(int)floor($h*$scale));
            $name=$id.'.'.$type;$meta['url'.$suffix]=$prefix.$name;
            if ($svg) {
                if ($suffix==='') $output=$bytes;
                else {
                    $variant=clone $doc;$root=$variant->documentElement;
                    if (!$root->hasAttribute('viewBox')) $root->setAttribute('viewBox',"0 0 $w $h");
                    $root->setAttribute('width',(string)$width);$root->setAttribute('height',(string)$height);$output=$variant->saveXML();
                }
                $variantSize=[0=>$width,1=>$height,2=>'',3=>"width='$width' height='$height'",'bits'=>'','mime'=>'svg+xml'];
            } else {
                $output=$this->raster($image,$w,$h,$width,$height,$mime);$variantSize=getimagesizefromstring($output);$variantSize[3]=str_replace('"',"'",$variantSize[3]);
            }
            $variantSize['scale']=$scale;
            if (!$svg || $suffix!=='') $meta['size'.$suffix]=$variantSize;
            if ($suffix!=='') { $meta['w'.$suffix]=$width;$meta['h'.$suffix]=$height; }
            $files[$group.'/'.$name]=$output;
        }
        if ($image) imagedestroy($image);
        return ['source'=>$source,'html'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'text'=>$meta['url'],'files'=>$files];
    }

    private function raster(\GdImage $image,int $w,int $h,int $width,int $height,string $mime): string
    {
        $target=imagecreatetruecolor($width,$height);
        if ($mime==='image/png' || $mime==='image/webp') { imagesavealpha($target,true);imagefill($target,0,0,imagecolorallocatealpha($target,0,0,0,127)); }
        elseif ($mime==='image/gif') { $transparent=imagecolorallocate($target,127,127,127);imagefill($target,0,0,$transparent);imagecolortransparent($target,$transparent); }
        imagecopyresampled($target,$image,0,0,0,0,$width,$height,$w,$h);
        ob_start();
        try { $ok=match($mime) {'image/png'=>imagepng($target,null,0),'image/jpeg'=>imagejpeg($target,null,100),'image/gif'=>imagegif($target),'image/webp'=>imagewebp($target,null,100)};$bytes=ob_get_contents(); }
        finally { ob_end_clean();imagedestroy($target); }
        if (!$ok) throw new \RuntimeException('Não foi possível gerar a imagem.');return $bytes;
    }

    public function publish(array $files): void
    {
        $storage = new MediaStorage($this->config);
        foreach ($files as $relative => $bytes) {
            $storage->write($relative, $bytes);
        }
    }

    public function cleanup(?string $oldJson,string $newJson,string $id): void
    {
        $old=json_decode($oldJson ?? '',true) ?: [];$new=json_decode($newJson,true) ?: [];
        foreach (['url','url_mini','url_cp'] as $key) {
            $path=$this->storageKey($old[$key] ?? '');
            // Delete only this article's legacy files, never a shared upload or an external URL.
            if ($path && $path!==$this->storageKey($new[$key] ?? '') && str_starts_with(basename($path),$id.'.')) (new MediaStorage($this->config))->delete($path);
        }
    }

    private function storageKey(string $url): ?string
    {
        $url=Media::rewrite($url,$this->config);$base=rtrim($this->config['media_public_url'],'/').'/';
        if (!str_starts_with($url,$base)) return null;
        $relative=rawurldecode(explode('?',substr($url,strlen($base)),2)[0]);
        if (!preg_match('~^(wiki|wiki_mini|wiki_cp)/[^/\\\\\x00]+$~D',$relative) || in_array(basename($relative),['.','..'],true)) return null;
        return $relative;
    }
}
