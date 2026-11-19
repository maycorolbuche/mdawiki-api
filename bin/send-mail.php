<?php
declare(strict_types=1);
use MDAWiki\{Database,LegacyPageRepository,LegacyMail,Mailer};
$config=require dirname(__DIR__).'/config/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
$db=new Database(LegacyPageRepository::connect($config['db'],$config['public_max_level'])->connection());
$result=(new LegacyMail($db,$config,Closure::fromCallable(new Mailer($config['mail'],$db))))->dispatch();
echo json_encode($result,JSON_UNESCAPED_UNICODE)."\n";
