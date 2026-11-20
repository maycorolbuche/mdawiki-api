<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = 'mysql:host=127.0.0.1;port=3306';
$db = new PDO($server, 'root', 'test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE migration_source');
$db->exec('CREATE DATABASE migration_target');
$db->exec('USE migration_source');
$db->exec(file_get_contents(__DIR__ . '/schema.mysql.sql'));

$a = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
$b = 'data:image/gif;base64,' . base64_encode(base64_decode(explode(',', $a, 2)[1], true) . "\0");
$page = $db->prepare('INSERT INTO PAGINAS (IDENT, NOME_URL, NOME, CONTEUDO_CODE, DT_ALTERACAO)
    VALUES (?, ?, ?, ?, ?)');
$page->execute(['article', 'Artigo_Exemplo', 'Artigo', 'Primeira versão', '2026-11-20 09:00:00']);
$page->execute(['imagem@sample', 'Imagem@Sample', 'Imagem', $a, '2026-11-20 09:00:00']);
$revision = $db->prepare("INSERT INTO PAGINAS_LOGS (NOME_URL_ID, IDENT, NOME_URL, CONTEUDO_CODE)
    VALUES (?, 'imagem@sample', 'Imagem@Sample', ?)");
$revision->execute([2, $a]);
$revision->execute([5, $b]);
$revision->execute([9, $a]);

$db->exec('USE migration_target');
$db->exec(file_get_contents(__DIR__ . '/../migrations/001_content_and_media.sql'));
$root = sys_get_temp_dir() . '/mdawiki-migration-' . bin2hex(random_bytes(8));
putenv("MEDIA_ROOT={$root}");
putenv("LEGACY_DB_DSN={$server};dbname=migration_source;charset=utf8mb4");
putenv("MIGRATION_DB_DSN={$server};dbname=migration_target;charset=utf8mb4");
foreach (['LEGACY_DB', 'MIGRATION_DB'] as $prefix) {
    putenv("{$prefix}_USER=root");
    putenv("{$prefix}_PASSWORD=test");
}
$run = static function (int $expected = 0): void {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/migrate-legacy.php'), $status);
    check($status === $expected, 'Código de saída inesperado do importador.');
};

$run();
$run();
check((int) $db->query('SELECT COUNT(*) FROM pages')->fetchColumn() === 2, 'Duplicação de páginas.');
check((int) $db->query('SELECT COUNT(*) FROM files')->fetchColumn() === 2, 'Falha de deduplicação.');
check($db->query('SELECT GROUP_CONCAT(revision_number ORDER BY legacy_revision_number) FROM page_revisions')->fetchColumn() === '1,2,3',
    'Sequência inicial incorreta.');
$links = $db->query('SELECT i.file_id FROM image_revisions i JOIN page_revisions r ON r.id = i.page_revision_id
    ORDER BY r.revision_number')->fetchAll(PDO::FETCH_COLUMN);
check(count($links) === 3 && $links[0] === $links[2] && $links[0] !== $links[1], 'Histórico A → B → A incorreto.');
foreach ($db->query('SELECT sha256, storage_key FROM files') as $file) {
    check(hash_file('sha256', $root . '/' . $file['storage_key']) === $file['sha256'], 'Binário incorreto.');
}

$db->exec('USE migration_source');
$db->exec("UPDATE PAGINAS SET CONTEUDO_CODE = 'Editado', DT_ALTERACAO = '2026-11-20 10:00:00' WHERE IDENT = 'article'");
$revision->execute([3, $b]);
$db->exec('USE migration_target');
$run();
check($db->query("SELECT source FROM pages WHERE legacy_identifier = 'article'")->fetchColumn() === 'Editado',
    'Artigo alterado não atualizado.');
check($db->query('SELECT GROUP_CONCAT(revision_number ORDER BY legacy_revision_number) FROM page_revisions')->fetchColumn() === '1,2,3,4',
    'Inserção tardia deixou buracos.');

$db->exec('USE migration_source');
$revision->execute([15, 'data:image/gif;base64,?']);
$db->exec('USE migration_target');
$run(1);
check((int) $db->query('SELECT COUNT(*) FROM page_revisions')->fetchColumn() === 4, 'Falha parcial gravou revisão inválida.');
echo "Migração testada com reexecução, atualização e deduplicação.\n";
