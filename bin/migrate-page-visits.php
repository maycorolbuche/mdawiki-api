<?php
declare(strict_types=1);

use MDAWiki\LegacyPageRepository;

if (PHP_SAPI !== 'cli') exit(1);
$config = require dirname(__DIR__) . '/config/bootstrap.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/008_page_visits.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração de visitas.');
LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection()->exec($sql);
echo "Tabela page_visits disponível.\n";
