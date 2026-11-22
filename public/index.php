<?php
declare(strict_types=1);

use MDAWiki\Application;
use MDAWiki\LegacyPageRepository;
use MDAWiki\NormalizedPageRepository;
use MDAWiki\{Database,Features,Session,Mailer,Media};

// PHP diagnostics belong in the server log, so the HTTP body stays JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = require dirname(__DIR__) . '/config/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalized = false;
$connection = null;
if ($path !== '/' && rtrim($path, '/') !== '/health' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') {
    try {
        $connection = LegacyPageRepository::connect($config['db'], $config['public_max_level'], $config);
        $normalized = (int) $connection->connection()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'identifier'")->fetchColumn() === 1;
        if ($normalized) {
            $connection->connection()->exec('SET NAMES utf8mb4');
        }
    } catch (Throwable $error) {
        // Let Application produce its regular structured connection error.
        $connection = null;
    }
}
$config['normalized_read_only'] = $normalized;
$config['normalized_editor'] = $normalized;
$repository = static function () use ($config, $normalized, $connection) {
    $legacy = $connection ?? LegacyPageRepository::connect($config['db'], $config['public_max_level'], $config);
    return $normalized
        ? new NormalizedPageRepository(new Database($legacy->connection()), $config['public_max_level'], $config['media_public_url'], $config['frontend_url'])
        : $legacy;
};
$app = new Application($repository, $config, static function() use ($config, $connection) {
    $db=new Database(($connection ?? LegacyPageRepository::connect($config['db'],$config['public_max_level']))->connection());
    return new Features($db,new Session(true,$config['storage_dir'].'/sessions'),$config,Closure::fromCallable(new Mailer($config['mail'],$db)));
});
if (str_starts_with($path, '/media/')) {
    (new Media($config))->serve($path, $normalized
        ? static fn (string $key): bool => $repository()->canServeArticleFile($key)
        : null);
}
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$body=[];
if (in_array($method,['POST','PUT'],true)) {
    $raw=file_get_contents('php://input',false,null,0,7000001);
    if (strlen($raw)>7000000) { (new MDAWiki\Response(413,['error'=>['code'=>'payload_too_large','message'=>'Arquivo muito grande.']]))->send();exit; }
    try { $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if (!is_array($body)) throw new JsonException(); }
    catch (JsonException $e) { (new MDAWiki\Response(400,['error'=>['code'=>'invalid_json','message'=>'JSON inválido.']]))->send();exit; }
}
$app->handle($method, rtrim($path, '/') ?: '/', $_GET, $_SERVER['HTTP_ORIGIN'] ?? '',$body,$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',$_SERVER['REMOTE_ADDR'] ?? 'unknown')->send();

