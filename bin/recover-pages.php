<?php
declare(strict_types=1);
use MDAWiki\{Accounts,Database,LegacyPageRepository,Pages,Session,WikiRenderer};
$config=require dirname(__DIR__).'/config/bootstrap.php';if (PHP_SAPI!=='cli') exit(1);
$db=new Database(LegacyPageRepository::connect($config['db'],$config['public_max_level'])->connection());
foreach (glob($config['storage_dir'].'/pending/*.json') ?: [] as $file) {
    $item=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);$u=$db->one('SELECT * FROM USUARIOS WHERE ID = ?',[(int)$item['user_id']]);
    if (!$u || $u['ATIVO']!=='S') { fwrite(STDERR,'Autor indisponível: '.$item['slug']."\n");continue; }
    $session=new Session();$session->data['id']=(int)$u['ID'];$session->data['fingerprint']=hash('sha256',$u['SENHA']);$session->data['expires']=time()+60;
    $accounts=new Accounts($db,$session,$config,static function(){});$pages=new Pages($db,$accounts,new WikiRenderer($db,$config),$config);
    $backup=$file.'.recovering';if (!rename($file,$backup)) throw new RuntimeException('Cannot lock recovery file');
    try { $page=$pages->source($item['slug']);$pages->save($item['slug'],['title'=>$item['title'],'source'=>$item['source'],'base_version'=>Pages::version($page)]);unlink($backup);echo 'Recuperada: '.$item['slug']."\n"; }
    catch (Throwable $e) { if (!is_file($file)) rename($backup,$file);fwrite(STDERR,$item['slug'].': '.$e->getMessage()."\n"); }
}
