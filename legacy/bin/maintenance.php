<?php
declare(strict_types=1);
use MDAWiki\{Accounts,Database,LegacyPageRepository,Pages,Session,WikiRenderer};
$config=require dirname(__DIR__).'/config/bootstrap.php';if (PHP_SAPI!=='cli') exit(1);
$storage=$config['storage_dir'];if (!is_dir($storage)) mkdir($storage,0700,true);
$lock=fopen($storage.'/maintenance.lock','c');if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
try {
    $outbox = new MDAWiki\MailOutbox($storage . '/mail-outbox', Closure::fromCallable(new MDAWiki\Mailer($config['mail'], errors: \MDAWiki\SystemErrorLog::fromConfig($config))));
    $delivery = $outbox->dispatch();
    echo 'E-mails: ' . $delivery['sent'] . ' enviados de ' . $delivery['checked'] . " tentativas.\n";
    $db=new Database(LegacyPageRepository::connect($config['db'],$config['public_max_level'])->connection());
    $normalized = (bool) $db->one("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND EXISTS (
            SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'identifier')");
    if ($normalized) {
        if ($db->one("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_visits'")) {
            $db->run('DELETE FROM page_visits WHERE visited_at < ? LIMIT 10000', [time() - 31 * 86400]);
        }
    } else {
        $pages=new Pages($db,new Accounts($db,new Session(),$config,static function(){}),new WikiRenderer($db,$config),$config);
        foreach($db->rows("SELECT NOME_URL FROM PAGINAS WHERE ATUALIZAR = 'S' AND IDENT NOT LIKE 'page@%' ORDER BY IDENT LIMIT 100") as $p) { try { $pages->rebuildCache($p['NOME_URL']);echo 'Processada: '.$p['NOME_URL']."\n"; } catch(Throwable $e) { fwrite(STDERR,$p['NOME_URL'].': '.$e->getMessage()."\n"); } }
    }
    foreach(glob($storage.'/rates/*.json') ?: [] as $file) if (filemtime($file)<time()-86400) unlink($file);
} finally {flock($lock,LOCK_UN);fclose($lock);}
