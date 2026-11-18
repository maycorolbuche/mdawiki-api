<?php
// Included by features.php against both SQLite and the original MySQL schema.
$stored=$db->one("SELECT * FROM PAGINAS WHERE IDENT='imagem@teste'");
verify($stored['CONTEUDO_CODE']===$png,'Store the base64 image source, not an upload URL');
$meta=json_decode($stored['CONTEUDO'],true);
verify($stored['CONTEUDO_TXT']==='@imgs_wiki/imagem@teste.png','CONTEUDO_TXT is the legacy image path');
foreach(['tipo','size','size_mini','size_cp','w','h','w_mini','h_mini','w_cp','h_cp','url','url_mini','url_cp'] as $key) verify(isset($meta[$key]),'Legacy image metadata key: '.$key);
verify($meta['w']===1 && $meta['h']===1 && $meta['w_mini']===1 && $meta['w_cp']===200,'Original, thumbnail and cover use the legacy dimensions');
foreach(['wiki'=>1,'wiki_mini'=>1,'wiki_cp'=>200] as $group=>$width) { $path=$config['media_root'].'/'.$group.'/imagem@teste.png';verify(is_file($path) && getimagesize($path)[0]===$width,'Generate the '.$group.' derivative'); }
verify(request('/v1/pages/imagem%40Teste/editor')->body['data']['source']===$png,'Image editor reads the base64 stored in the legacy source field');
verify(request('/v1/pages/imagem%40Teste/history')->body['data']['revisions'][0]['source']===$png,'Image history preserves the recoverable base64 source');
verify(Media::rewrite('@imgs_wiki_mini/imagem@teste.png',$config)==='https://api.mda.wiki.br/media/wiki_mini/imagem@teste.png','Map legacy thumbnail paths on read');
verify(Media::rewrite('https://mda.wiki.br/site/@imgs_wiki_cp/imagem@teste.png',$config)==='https://api.mda.wiki.br/media/wiki_cp/imagem@teste.png','Map legacy cover paths on read');
$version=request('/v1/pages/imagem%40Teste/editor')->body['data']['base_version'];
$invalid=request('/v1/pages/imagem%40Teste','PUT',['title'=>'Imagem teste','source'=>'https://outside.example.test/image.png','base_version'=>$version]);
verify($invalid->status===422 && $db->one("SELECT CONTEUDO_CODE FROM PAGINAS WHERE IDENT='imagem@teste'")['CONTEUDO_CODE']===$png,'External URLs never replace the stored image');
$removed=request('/v1/pages/imagem%40Teste','PUT',['title'=>'Imagem teste','source'=>'','base_version'=>$version]);verify($removed->status===200,'Remove image');
$empty=$db->one("SELECT CONTEUDO_CODE,CONTEUDO,CONTEUDO_TXT FROM PAGINAS WHERE IDENT='imagem@teste'");verify($empty===['CONTEUDO_CODE'=>'','CONTEUDO'=>'','CONTEUDO_TXT'=>''],'Removal clears all three legacy fields');
foreach(['wiki','wiki_mini','wiki_cp'] as $group) verify(!is_file($config['media_root'].'/'.$group.'/imagem@teste.png'),'Removal clears this article derivative: '.$group);
verify(request('/v1/pages/imagem%40Teste/restore','POST',['revision'=>1,'base_version'=>$removed->body['data']['base_version']])->status===200,'Restore image without depending on the removed files');
verify(is_file($config['media_root'].'/wiki_cp/imagem@teste.png'),'Restoring a revision regenerates derivatives');
$db->run("UPDATE PAGINAS SET CONTEUDO='',CONTEUDO_TXT='' WHERE IDENT='imagem@teste'");
unlink($config['media_root'].'/wiki_mini/imagem@teste.png');
$imagePages=new MDAWiki\Pages($db,new Accounts($db,$session,$config,$send),new WikiRenderer($db,$config),$config);
$imagePages->rebuildCache('imagem@Teste');
verify(is_file($config['media_root'].'/wiki_mini/imagem@teste.png') && $db->one("SELECT CONTEUDO_TXT FROM PAGINAS WHERE IDENT='imagem@teste'")['CONTEUDO_TXT']==='@imgs_wiki/imagem@teste.png','Rebuild recovers files and fields from the persisted base64');

$large=imagecreatetruecolor(700,400);ob_start();imagepng($large,null,0);$largeBytes=ob_get_clean();imagedestroy($large);
$largeSource='data:image/png;base64,'.base64_encode($largeBytes);verify(strlen($largeSource)>500000,'Large image fixture exercises the former article-only size limit');
$largeSave=request('/v1/pages/imagem%40Grande','PUT',['title'=>'Grande','source'=>$largeSource,'base_version'=>'']);verify($largeSave->status===200,'Accept image sources above 500 KB');
$largeRow=$db->one("SELECT CONTEUDO_CODE,CONTEUDO,CONTEUDO_TXT FROM PAGINAS WHERE IDENT='imagem@grande'");$largeMeta=json_decode($largeRow['CONTEUDO'],true);
verify($largeMeta['w']===500 && $largeMeta['h']===285 && $largeMeta['w_mini']===100 && $largeMeta['h_mini']===57,'Legacy raster bounds preserve the aspect ratio');
verify(getimagesizefromstring(base64_decode(explode(',',$largeRow['CONTEUDO_CODE'],2)[1]))[0]===500,'Resized base64 matches the generated original');
$jpeg=imagecreatetruecolor(80,40);ob_start();imagejpeg($jpeg);$jpegSource='data:image/jpeg;base64,'.base64_encode(ob_get_clean());imagedestroy($jpeg);
$jpegSave=request('/v1/pages/imagem%40Grande','PUT',['title'=>'Grande','source'=>$jpegSource,'base_version'=>$largeSave->body['data']['base_version']]);verify($jpegSave->status===200,'Replace the image format');
verify(!is_file($config['media_root'].'/wiki/imagem@grande.png') && is_file($config['media_root'].'/wiki/imagem@grande.jpeg'),'Format replacement removes obsolete derivatives');

$svgSource='data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="200"><rect width="400" height="200" fill="red"/></svg>');
$svgSave=request('/v1/pages/imagem%40Vetor','PUT',['title'=>'Vetor','source'=>$svgSource,'base_version'=>'']);verify($svgSave->status===200,'Save vector image in legacy fields');
$vector=$db->one("SELECT CONTEUDO_CODE,CONTEUDO FROM PAGINAS WHERE IDENT='imagem@vetor'");$vectorMeta=json_decode($vector['CONTEUDO'],true);
verify(str_starts_with($vector['CONTEUDO_CODE'],'data:image/svg+xml;base64,') && $vectorMeta['tipo']==='svg' && $vectorMeta['w_mini']===100 && $vectorMeta['h_mini']===50,'SVG metadata preserves dimensions and vector format');
$miniDoc=new DOMDocument();$miniDoc->load($config['media_root'].'/wiki_mini/imagem@vetor.svg');verify($miniDoc->documentElement->getAttribute('viewBox')==='0 0 400 200','SVG thumbnail has a stable viewBox');
$badSvg='data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
verify(request('/v1/pages/imagem%40Ativa','PUT',['title'=>'Ativa','source'=>$badSvg,'base_version'=>''])->status===422,'Direct base64 image saves retain SVG validation');
