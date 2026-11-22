<?php
declare(strict_types=1);

use MDAWiki\LegacyPageRepository;

if (PHP_SAPI !== 'cli') exit(1);
$config = require dirname(__DIR__) . '/config/bootstrap.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/006_normalized_registration.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração de cadastro.');
$pdo = LegacyPageRepository::connect($config['db'], $config['public_max_level'])->connection();
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') $pdo->exec($statement);
}
echo "Estrutura de cadastro disponível.\n";
