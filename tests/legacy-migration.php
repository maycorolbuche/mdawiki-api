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
$db->exec(file_get_contents(__DIR__ . '/schema.migration.mysql.sql'));

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
$insertUser = $db->prepare("INSERT INTO USUARIOS (ID, EMAIL, SENHA, ATIVO, FOTO_64) VALUES (?, ?, ?, 'S', ?)");
$insertUser->execute([100, 'migrated@example.test', md5('legacy-password'), $a]);
$insertUser->execute([101, 'reset@example.test', 'unsupported', null]);
$db->exec("INSERT INTO CONFIG (CHAVE, VALOR) VALUES ('test', 'first')");
$db->exec("INSERT INTO ACESSOS_LOGS (UID) VALUES ('same'), ('same')");

$db->exec('USE migration_target');
$root = sys_get_temp_dir() . '/mdawiki-migration-' . bin2hex(random_bytes(8));
putenv("MEDIA_ROOT={$root}");
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_DATABASE=migration_source');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=test');
putenv('DB_CHARSET=utf8mb4');
putenv('MIGRATION_DB_HOST=127.0.0.1');
putenv('MIGRATION_DB_PORT=3306');
putenv('MIGRATION_DB_DATABASE=migration_target');
putenv('MIGRATION_DB_USERNAME=root');
putenv('MIGRATION_DB_PASSWORD=test');
$run = static function (int $expected = 0): void {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/migrate-legacy.php'), $status);
    check($status === $expected, 'Código de saída inesperado do importador.');
};

$run();
check($db->query('SELECT password_hash FROM users WHERE id = 101')->fetchColumn() === '', 'Hash incompatível não ficou vazio.');
check($db->query('SELECT password_hash FROM users WHERE id = 100')->fetchColumn() === md5('legacy-password'), 'Hash compatível perdido.');
check((int) $db->query('SELECT avatar_file_id FROM users WHERE id = 100')->fetchColumn() > 0, 'Avatar não vinculado.');
$db->exec("UPDATE users SET password_hash = 'reset-in-destination' WHERE id = 101");
$run();
check($db->query('SELECT password_hash FROM users WHERE id = 101')->fetchColumn() === 'reset-in-destination', 'Reexecução sobrescreveu senha.');
check((int) $db->query('SELECT COUNT(*) FROM session_events')->fetchColumn() === 2, 'Ocorrências sem chave não foram preservadas.');
check($db->query("SELECT setting_value FROM settings WHERE setting_key = 'test'")->fetchColumn() === 'first', 'Configuração não importada.');
check((int) $db->query('SELECT COUNT(*) FROM pages')->fetchColumn() === 2, 'Duplicação de páginas.');
check((int) $db->query('SELECT COUNT(*) FROM files')->fetchColumn() === 3, 'Deduplicação por pasta incorreta.');
check($db->query('SELECT GROUP_CONCAT(revision_number ORDER BY legacy_revision_number) FROM page_revisions')->fetchColumn() === '1,2,3',
    'Sequência inicial incorreta.');
$links = $db->query('SELECT i.file_id FROM image_revisions i JOIN page_revisions r ON r.id = i.page_revision_id
    ORDER BY r.revision_number')->fetchAll(PDO::FETCH_COLUMN);
check(count($links) === 3 && $links[0] === $links[2] && $links[0] !== $links[1], 'Histórico A → B → A incorreto.');
foreach ($db->query('SELECT sha256, storage_key FROM files') as $file) {
    check(preg_match('~^(articles|avatars)/[a-f0-9]{64}\.gif$~D', $file['storage_key']) === 1, 'A chave deve incluir a pasta de mídia.');
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
$insertUser->execute([102, 'broken-avatar@example.test', 'unsupported', 'data:image/gif;base64,?']);
$db->exec('USE migration_target');
$run(1);
check((int) $db->query('SELECT COUNT(*) FROM page_revisions')->fetchColumn() === 4, 'Falha parcial gravou revisão inválida.');
echo "Migração testada com reexecução, atualização e deduplicação.\n";

check((int) $db->query("SELECT COUNT(*) FROM files WHERE storage_key LIKE 'avatars/%'")->fetchColumn() === 1, 'Pasta de avatares incorreta.');
check((int) $db->query("SELECT COUNT(*) FROM migration_errors WHERE source_table = 'USUARIOS' AND source_field = 'FOTO_64'")->fetchColumn() === 1, 'Erro de avatar não registrado.');
check((int) $db->query("SELECT COUNT(*) FROM migration_errors WHERE source_table = 'PAGINAS_LOGS' AND source_field = 'CONTEUDO_CODE'")->fetchColumn() === 1, 'Erro de revisão não registrado.');
