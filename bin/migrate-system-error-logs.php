<?php
declare(strict_types=1);

use MDAWiki\LegacyPageRepository;

if (PHP_SAPI !== 'cli') exit(1);
$config = require dirname(__DIR__) . '/config/bootstrap.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/005_system_error_logs.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração de logs.');
LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection()->exec($sql);
echo "Tabela system_error_logs disponível.\n";
