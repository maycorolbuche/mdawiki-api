<?php
declare(strict_types=1);

use MDAWiki\Application;
use MDAWiki\LegacyPageRepository;

$config = require dirname(__DIR__) . '/config/bootstrap.php';
$app = new Application(static fn () => LegacyPageRepository::connect($config['db'], $config['public_max_level']), $config);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/', $_GET, $_SERVER['HTTP_ORIGIN'] ?? '')->send();
