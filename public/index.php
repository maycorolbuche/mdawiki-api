<?php
declare(strict_types=1);

use MDAWiki\Application;
use MDAWiki\LegacyPageRepository;
use MDAWiki\{Database,Features,Session,Mailer,Media};

$config = require dirname(__DIR__) . '/config/bootstrap.php';
$app = new Application(static fn () => LegacyPageRepository::connect($config['db'], $config['public_max_level'],$config), $config, static function() use ($config) {
    $db=new Database(LegacyPageRepository::connect($config['db'],$config['public_max_level'])->connection());
    return new Features($db,new Session(true),$config,Closure::fromCallable(new Mailer($config['mail'],$db)));
});
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path,'/media/')) (new Media($config))->serve($path);
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$body=[];
if (in_array($method,['POST','PUT'],true)) {
    $raw=file_get_contents('php://input',false,null,0,7000001);
    if (strlen($raw)>7000000) { (new MDAWiki\Response(413,['error'=>['code'=>'payload_too_large','message'=>'Arquivo muito grande.']]))->send();exit; }
    try { $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if (!is_array($body)) throw new JsonException(); }
    catch (JsonException $e) { (new MDAWiki\Response(400,['error'=>['code'=>'invalid_json','message'=>'JSON inválido.']]))->send();exit; }
}
$app->handle($method, rtrim($path, '/') ?: '/', $_GET, $_SERVER['HTTP_ORIGIN'] ?? '',$body,$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',$_SERVER['REMOTE_ADDR'] ?? 'unknown')->send();

