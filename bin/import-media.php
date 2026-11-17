<?php
declare(strict_types=1);
use MDAWiki\{Database,LegacyPageRepository,Media};
$config=require dirname(__DIR__).'/config/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
$source=realpath($argv[1] ?? '');
if (!$source || !is_dir($source.'/@imgs_wiki')) { fwrite(STDERR,"Uso: php bin/import-media.php /caminho/para/site-legado\n");exit(1); }
$total=0;
foreach (['@imgs_wiki'=>'wiki','@imgs_user'=>'users','@imgs'=>'assets'] as $folder=>$group) {
    if (!is_dir($source.'/'.$folder)) continue;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$folder,FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isLink() || !$file->isFile() || !in_array(strtolower($file->getExtension()),['png','jpg','jpeg','gif','webp','avif','svg'],true)) continue;
        $relative=substr($file->getPathname(),strlen($source.'/'.$folder)+1);$target=$config['media_root'].'/'.$group.'/'.$relative;
        if (is_file($target)) continue;
        if (!is_dir(dirname($target))) mkdir(dirname($target),0755,true);
        if (!copy($file->getPathname(),$target)) throw new RuntimeException('Não foi possível copiar '.$relative);$total++;
    }
}
// Recover embedded avatars where a generated legacy file is absent.
$db=new Database(LegacyPageRepository::connect($config['db'],$config['public_max_level'])->connection());$media=new Media($config);
foreach ($db->rows("SELECT ID,FOTO,FOTO_64 FROM USUARIOS WHERE FOTO_64 IS NOT NULL AND FOTO_64 <> ''") as $user) {
    if ($user['FOTO'] && is_file($config['media_root'].'/users/'.basename($user['FOTO']))) continue;
    if (!str_starts_with($user['FOTO_64'],'data:image/')) continue;
    $file=$media->upload(['data'=>$user['FOTO_64']],'users');$db->run('UPDATE USUARIOS SET FOTO = ?, FOTO_32X32 = ? WHERE ID = ?',[$file['filename'],$file['filename'],(int)$user['ID']]);$total++;
}
echo "Importação concluída: $total arquivos copiados. Arquivos existentes preservados.\n";
