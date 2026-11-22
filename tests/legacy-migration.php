<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
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
$page->execute(['article', 'Artigo_Exemplo', 'EspéciAlidade Teste', "Primeira versão\n==Introdução==", '2026-11-20 09:00:00']);
$page->execute(['imagem@sample', 'Imagem@Sample', 'Imagem', $a, '2026-11-20 09:00:00']);
$page->execute(['doc@manual', 'Manual', 'ESPEcialidade Teste', 'Documento obsoleto', '2026-11-20 09:00:00']);
$page->execute(['documentacao', 'DOC@Manual', 'Outro documento', 'Documento obsoleto', '2026-11-20 09:00:00']);
$revision = $db->prepare("INSERT INTO PAGINAS_LOGS (NOME_URL_ID, IDENT, NOME_URL, CONTEUDO_CODE)
    VALUES (?, 'imagem@sample', 'Imagem@Sample', ?)");
$revision->execute([2, $a]);
$revision->execute([5, $b]);
$revision->execute([9, $a]);
$db->exec("INSERT INTO PAGINAS_CATEGORIAS (IDENT, NOME_URL, CATEGORIA_IDENT, CATEGORIA_URL) VALUES ('article', 'Artigo_Exemplo', 'category', 'Categoria')");
$db->exec('DROP TABLE ACESSOS_LOGS, EMAIL_LOGS, CRONJOB_LOGS');
$db->exec("INSERT INTO COMENTARIOS (ID, NOME_URL) VALUES (1, 'Artigo_Exemplo'), (2, 'Pagina_Ausente')");
$insertUser = $db->prepare("INSERT INTO USUARIOS (ID, EMAIL, SENHA, ATIVO, FOTO_64) VALUES (?, ?, ?, 'S', ?)");
$insertUser->execute([100, 'migrated@example.test', md5('legacy-password'), $a]);
$insertUser->execute([101, 'reset@example.test', 'unsupported', null]);
$db->exec("INSERT INTO CONFIG (CHAVE, VALOR) VALUES ('test', 'first')");

$db->exec("UPDATE PAGINAS SET USU_CADASTRO = 'migrated@example.test', DT_CADASTRO = '2020-01-01 00:00:00'");
$db->exec("UPDATE PAGINAS_LOGS SET EMAIL = 'migrated@example.test'");
$db->exec('USE migration_target');
$root = sys_get_temp_dir() . '/mdawiki-migration-' . bin2hex(random_bytes(8));
putenv("MEDIA_ROOT={$root}");
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_DATABASE=migration_target');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=test');
putenv('DB_CHARSET=utf8mb4');
putenv('MIGRATION_DB_HOST=127.0.0.1');
putenv('MIGRATION_DB_PORT=3306');
putenv('MIGRATION_DB_DATABASE=migration_source');
putenv('MIGRATION_DB_CHARSET=utf8mb4');
putenv('MIGRATION_DB_USERNAME=root');
putenv('MIGRATION_DB_PASSWORD=test');
$run = static function (int $expected = 0): void {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/migrate-legacy.php'), $status);
    check($status === $expected, 'Código de saída inesperado do importador.');
};

foreach (['mail_template_recipients', 'mail_templates', 'mail_profile_settings'] as $obsolete) {
    $db->exec("CREATE TABLE {$obsolete} (id INT PRIMARY KEY)");
}
$run();
check((int) $db->query("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('mail_template_recipients', 'mail_templates', 'mail_profile_settings')")->fetchColumn() === 0,
    'As tabelas obsoletas de e-mail devem ser removidas da base migrada.');
foreach (['pages', 'images', 'image_revisions'] as $table) {
    check((int) $db->query("SELECT MIN(created_by) FROM $table")->fetchColumn() === 100,
        "A migração deve localizar o autor por e-mail e gravar o ID em $table.");
}
check($db->query('SELECT created_at FROM images')->fetchColumn() === '2020-01-01 00:00:00',
    'Data de criação da imagem deve vir do legado.');

check((int) $db->query('SELECT COUNT(*) FROM page_categories')->fetchColumn() === 0, 'Categoria derivada foi importada.');
check((int) $db->query("SELECT needs_rebuild FROM pages WHERE legacy_identifier = 'article'")->fetchColumn() === 0, 'O importador deve concluir a reconstrução.');
check(array_column($db->query('SHOW COLUMNS FROM page_categories')->fetchAll(PDO::FETCH_ASSOC), 'Field')
    === ['id', 'page_id', 'category_id', 'position'], 'Estrutura de categorias incorreta.');
$importedPage = $db->query("SELECT id, slug, identifier FROM pages WHERE legacy_identifier = 'article'")->fetch(PDO::FETCH_ASSOC);
check($importedPage['slug'] === 'EspéciAlidade-Teste' && $importedPage['identifier'] === 'especialidade-teste', 'Slug e identificador incorretos.');
check((int) $db->query('SELECT page_id FROM comments WHERE id = 1')->fetchColumn() === (int) $importedPage['id'], 'Comentário sem vínculo por ID.');
check($db->query('SELECT page_id FROM comments WHERE id = 2')->fetchColumn() === null, 'Referência inexistente recebeu ID.');
check($db->query('SELECT password_hash FROM users WHERE id = 101')->fetchColumn() === '', 'Hash incompatível não ficou vazio.');
check($db->query('SELECT password_hash FROM users WHERE id = 100')->fetchColumn() === md5('legacy-password'), 'Hash compatível perdido.');
check(preg_match('/^[a-f0-9]{64}\.gif$/D', $db->query('SELECT avatar_filename FROM users WHERE id = 100')->fetchColumn()) === 1, 'Avatar não vinculado.');
$db->exec("UPDATE users SET password_hash = 'reset-in-destination' WHERE id = 101");
check((int) $db->query('SELECT COUNT(*) FROM page_headings')->fetchColumn() === 1, 'Reprocessamento não reconstruiu subtítulos.');
$db->exec('DROP TABLE page_headings');
$removedMedia = $root . '/' . $db->query("SELECT storage_key FROM files WHERE storage_key LIKE 'articles/%' LIMIT 1")->fetchColumn();
check(unlink($removedMedia), 'A simulação de limpeza de mídia falhou.');
$publicId = $db->query('SELECT public_id FROM users WHERE id = 100')->fetchColumn();
$run();
check($db->query('SELECT public_id FROM users WHERE id = 100')->fetchColumn() === $publicId, 'Perfil público mudou na reexecução.');
check(is_file($removedMedia), 'Reexecução não recuperou arquivo removido.');
check((int) $db->query('SELECT COUNT(*) FROM page_headings')->fetchColumn() === 1, 'Reexecução não recriou a tabela e seus subtítulos.');
check($db->query('SELECT password_hash FROM users WHERE id = 101')->fetchColumn() === 'reset-in-destination', 'Reexecução sobrescreveu senha.');
check($db->query("SELECT setting_value FROM settings WHERE setting_key = 'test'")->fetchColumn() === 'first', 'Configuração não importada.');
check((int) $db->query('SELECT COUNT(*) FROM pages')->fetchColumn() === 1, 'Duplicação de páginas.');
check((int) $db->query('SELECT COUNT(*) FROM files')->fetchColumn() === 3, 'Deduplicação por pasta incorreta.');
check($db->query('SELECT GROUP_CONCAT(legacy_revision_number ORDER BY legacy_revision_number) FROM image_revisions')->fetchColumn() === '2,5,9',
    'Sequência inicial incorreta.');
$links = $db->query('SELECT file_id FROM image_revisions ORDER BY legacy_revision_number')->fetchAll(PDO::FETCH_COLUMN);
check(count($links) === 3 && $links[0] === $links[2] && $links[0] !== $links[1], 'Histórico A → B → A incorreto.');
foreach ($db->query('SELECT sha256, storage_key FROM files') as $file) {
    check(preg_match('~^(articles|users)/[a-f0-9]{64}\.gif$~D', $file['storage_key']) === 1, 'A chave deve incluir a pasta de mídia.');
    check(hash_file('sha256', $root . '/' . $file['storage_key']) === $file['sha256'], 'Binário incorreto.');
}

$db->exec('USE migration_source');
$db->exec("UPDATE PAGINAS SET CONTEUDO_CODE = 'Editado', DT_ALTERACAO = '2026-11-20 10:00:00' WHERE IDENT = 'article'");
$revision->execute([3, $b]);
$db->exec('USE migration_target');
$run();
check($db->query("SELECT source FROM pages WHERE legacy_identifier = 'article'")->fetchColumn() === 'Editado',
    'Artigo alterado não atualizado.');
check($db->query('SELECT GROUP_CONCAT(legacy_revision_number ORDER BY legacy_revision_number) FROM image_revisions')->fetchColumn() === '2,3,5,9',
    'Inserção tardia deixou buracos.');

$db->exec('USE migration_source');
$page->execute(['collision', 'Conflito_Legado', 'ESPEcialidade Teste', 'Não deve sobrescrever', '2026-11-20 09:00:00']);
$revision->execute([15, 'data:image/gif;base64,?']);
$insertUser->execute([102, 'broken-avatar@example.test', 'unsupported', 'data:image/gif;base64,?']);
$db->exec('USE migration_target');
$run(1);
check((int) $db->query('SELECT COUNT(*) FROM image_revisions')->fetchColumn() === 4, 'Falha parcial gravou revisão inválida.');
echo "Migração testada com reexecução, atualização e deduplicação.\n";

check((int) $db->query("SELECT COUNT(*) FROM files WHERE storage_key LIKE 'users/%'")->fetchColumn() === 1, 'Pasta de avatares incorreta.');
check((int) $db->query("SELECT COUNT(*) FROM migration_errors WHERE source_table = 'USUARIOS' AND source_field = 'FOTO_64'")->fetchColumn() === 1, 'Erro de avatar não registrado.');
check((int) $db->query("SELECT COUNT(*) FROM migration_errors WHERE source_table = 'PAGINAS_LOGS' AND source_field = 'CONTEUDO_CODE'")->fetchColumn() === 1, 'Erro de revisão não registrado.');

check((int) $db->query("SELECT COUNT(*) FROM pages WHERE legacy_identifier = 'collision'")->fetchColumn() === 0, 'Colisão sobrescreveu uma página.');
check((int) $db->query("SELECT COUNT(*) FROM migration_errors WHERE source_table = 'PAGINAS' AND source_field = 'NOME'")->fetchColumn() === 1, 'Colisão não registrada.');


$config = require dirname(__DIR__) . '/config/bootstrap.php';
$normalized = new MDAWiki\NormalizedPageRepository(new MDAWiki\Database($db), 1, 'https://api.example.test/media');
$preview = new MDAWiki\Application(static fn () => $normalized, [
    'normalized_read_only' => true, 'origins' => [], 'frontend_url' => 'https://example.test',
    'media_url' => 'https://api.example.test/media',
]);
$export = $preview->handle('GET', '/v1/pages', ['limit' => 1]);
check($export->status === 200 && $export->body['data'][0]['read_only'] === true, 'Exportação da base migrada falhou.');
check($export->body['next_cursor'] === null, 'Imagem não deve entrar na exportação de páginas.');
$article = $preview->handle('GET', '/v1/pages/ESPECIALIDADE_Teste', []);
check($article->status === 200 && $article->body['data']['slug'] === 'EspéciAlidade-Teste', 'Slug canônico da base migrada incorreto.');
check($preview->handle('PUT', '/v1/pages/Especialidade-Teste', [])->status === 501, 'Prévia aceitou uma edição.');

$db->exec("UPDATE pages SET access_level_id = 5 WHERE legacy_identifier = 'article'");
check($preview->handle('GET', '/v1/pages/Especialidade-Teste', [])->status === 404, 'Prévia expôs artigo privado.');

$search = $preview->handle('GET', '/v1/search', ['q' => 'Imagem']);
check($search->status === 200 && count($search->body['data']['items']) === 0, 'Busca da base importada falhou.');
$home = $preview->handle('GET', '/v1/home', []);
check($home->status === 200 && count($home->body['data']['recent']) === 0, 'Início da base importada não respeitou a visibilidade.');


$authConfig = [
    'normalized_editor' => true,
    'normalized_read_only' => true, 'origins' => ['http://localhost:5173'],
    'frontend_url' => 'https://example.test', 'media_public_url' => 'https://api.example.test/media',
    'storage_dir' => $root, 'media_root' => $root, 'app_key' => str_repeat('a', 32), 'public_max_level' => 1,
];
$authConfig['mail'] = ['admin_to' => 'admin@example.test'];
$subjects = [];
$activationMail = '';
$mailBody = '';
$session = new MDAWiki\Session(false);
$features = new MDAWiki\Features(new MDAWiki\Database($db), $session, $authConfig,
    static function (string $to, string $subject, string $body) use (&$subjects, &$mailBody, &$activationMail): void {
        $subjects[] = $subject;
        $mailBody = $body;
        if (str_contains($body, 'p/ativar-conta?code=')) $activationMail = $body;
    });
$auth = new MDAWiki\Application(static fn () => $normalized, $authConfig, static fn () => $features);
$origin = 'http://localhost:5173';
$publicUser = $auth->handle('GET', '/v1/public-users/' . $publicId, [], $origin);
check($publicUser->status === 200 && str_contains($publicUser->body['data']['photo'], '/media/users/')
    && array_keys($publicUser->body['data']) === ['name', 'display_name', 'birth_date', 'city', 'state_code', 'club',
        'association', 'club_type', 'biography', 'photo'], 'Perfil público expôs campos privados ou perdeu o avatar.');
$initial = $auth->handle('GET', '/v1/auth/session', [], $origin);
$credentials = ['email' => 'migrated@example.test', 'password' => 'legacy-password'];
check($auth->handle('POST', '/v1/auth/login', [], $origin, $credentials, 'wrong')->status === 403, 'Login ignorou CSRF.');
$login = $auth->handle('POST', '/v1/auth/login', [], $origin, $credentials, $initial->body['data']['csrf_token']);
check($login->status === 200 && (int) $login->body['data']['user']['ID'] === 100, 'Login do usuário migrado falhou.');
check($login->body['data']['user']['photo'] === $publicUser->body['data']['photo'], 'Avatar migrado não apareceu na sessão.');
check($auth->handle('GET', '/v1/images/Sample', [], $origin)->status === 200, 'Imagem migrada indisponível.');
$db->exec("UPDATE pages SET access_level_id = 1 WHERE legacy_identifier = 'article'");
$slug = '/v1/pages/Especialidade_Teste';
$public = $auth->handle('GET', $slug, [], $origin);
check($public->status === 200 && $public->body['data']['read_only'] === false, 'Botões do artigo ficaram ocultos.');
$editor = $auth->handle('GET', $slug . '/editor', [], $origin);
check($editor->status === 200 && $editor->body['data']['source'] === 'Editado', 'Editor não abriu o artigo migrado.');
$csrf = $login->body['data']['csrf_token'];
$change = ['title' => 'EspéciAlidade Teste', 'source' => 'Texto novo', 'base_version' => $editor->body['data']['base_version']];
check($auth->handle('POST', $slug . '/preview', [], $origin, $change, $csrf)->status === 200, 'Prévia do artigo falhou.');
$saved = $auth->handle('PUT', $slug, [], $origin, $change, $csrf);
check($saved->status === 200, 'Salvamento do artigo migrado falhou.');
check(end($subjects) === '[page][update] EspéciAlidade Teste', 'Alteração do artigo deve notificar o admin.');
check($auth->handle('PUT', $slug, [], $origin, $change, $csrf)->status === 409, 'Versão desatualizada sobrescreveu artigo.');
check(str_contains($mailBody, 'href="https://example.test/Esp%C3%A9ciAlidade-Teste"'), 'E-mail deve usar o endereço canônico do artigo.');
$history = $auth->handle('GET', $slug . '/history', [], $origin);
check($history->status === 200 && $history->body['data']['revisions'][0]['source'] === 'Texto novo', 'Revisão salva não consta no histórico.');
$db->exec("UPDATE users SET access_level_id = 5 WHERE id = 100");
$imageId = $db->query('SELECT id FROM images')->fetchColumn();
$articleId = $importedPage['id'];
$change = [
    'title' => 'EspéciAlidade Teste',
    'source' => "{{Categorias:Imagem, IMAGEM}}{{Categorias:Estudos da Natureza, abc, ÁBC}}\n{{Imagem:Sample}}\n{{Video:https://example.test/video|Legenda}}\n==Seção==\n[[Imagem#Detalhes|Ver imagem]] [[https://example.test/|Site]]",
    'base_version' => $history->body['data']['base_version'],
];
$saved = $auth->handle('PUT', $slug, [], $origin, $change, $csrf);
check($saved->status === 200, 'Salvamento com vínculos falhou.');
$categoryIds = $db->query("SELECT id FROM pages WHERE identifier IN ('estudos-da-natureza', 'abc') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check(count($categoryIds) === 2, 'Categorias ausentes não foram criadas uma vez por identificador.');
$targetId = $db->query("SELECT id FROM pages WHERE identifier = 'imagem'")->fetchColumn();
$expectedCategories = [$targetId, ...$categoryIds];
check($db->query("SELECT category_id FROM page_categories WHERE page_id = $articleId ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)
    === $expectedCategories, 'Categorias sem vínculo por ID ou fora de ordem.');
$abc = $auth->handle('GET', '/v1/pages/abc', [], $origin);
check($abc->status === 200 && $abc->body['data']['html'] === ''
    && (int) $abc->body['data']['category_members'][0]['id'] === (int) $articleId, 'Página de categoria vazia ou lista de artigos incorreta.');
foreach (['page_media' => 'image_id', 'page_links' => 'target_page_id'] as $table => $column) {
    check($db->query("SELECT $column FROM $table WHERE page_id = $articleId")->fetchAll(PDO::FETCH_COLUMN) === [$table === 'page_media' ? $imageId : $targetId],
        "Vínculo por ID incorreto em $table.");
}
foreach (['page_templates', 'page_headings', 'page_external_links', 'page_videos'] as $table) {
    check((int) $db->query("SELECT COUNT(*) FROM $table WHERE page_id = $articleId")->fetchColumn() > 0,
        "Salvamento não preencheu $table.");
}
$db->exec("DELETE FROM page_categories WHERE page_id = $articleId");
$db->exec("UPDATE pages SET rendered_html = 'desatualizado', needs_rebuild = 1 WHERE id = $articleId");
$queue = $auth->handle('GET', '/v1/catalog', ['type' => 'atualizar'], $origin);
check($queue->status === 200 && (int) $queue->body['data']['items'][0]['id'] === (int) $articleId, 'Página pendente ausente da fila.');
$beforeRebuild = count($subjects);
$rebuilt = $auth->handle('POST', $slug . '/rebuild', [], $origin, [], $csrf);
check($rebuilt->status === 200 && $rebuilt->body['data'] === $saved->body['data'], 'Atualizar alterou a revisão ou versão de edição.');
check((int) $db->query("SELECT COUNT(*) FROM page_revisions WHERE page_id = $articleId")->fetchColumn() === 2, 'Atualizar criou histórico.');
check($db->query("SELECT category_id FROM page_categories WHERE page_id = $articleId ORDER BY position")->fetchAll(PDO::FETCH_COLUMN) === $expectedCategories, 'Atualizar não refez categorias.');
check($db->query("SELECT rendered_html FROM pages WHERE id = $articleId")->fetchColumn() !== 'desatualizado', 'Atualizar não refez HTML.');
check(count($subjects) === $beforeRebuild, 'Reprocessamento não deve enviar e-mail.');
$imageKey = $db->query("SELECT f.storage_key FROM images i JOIN files f ON f.id = i.file_id WHERE i.id = $imageId")->fetchColumn();

check($normalized->canServeArticleFile($imageKey), 'Imagem pública fora da busca deve carregar nos artigos.');
$db->exec("UPDATE images SET access_level_id = 5 WHERE id = $imageId");
check(!$normalized->canServeArticleFile($imageKey), 'Arquivo de imagem restrita ficou público.');
$db->exec("UPDATE images SET access_level_id = 1 WHERE id = $imageId");
$db->prepare('UPDATE pages SET rendered_html = ? WHERE id = ?')->execute([
    '<a href="https://mda.wiki.br/imagem@Sample"><img src="{{{url_diretorio}}}@imgs/img_nao_encontrada.png"></a>'
    . '<a href="{{{url_site}}}Nao_Cadastrada#Topico" class="wiki">Link ausente</a>'
    . '<a href="https://example.org/Fora/">Externo</a>', $articleId]);
$relatedId = $categoryIds[0];
$db->exec("INSERT INTO page_links (page_id, target_page_id, position) VALUES ($relatedId, $articleId, 1)");
$db->exec("INSERT INTO page_categories (page_id, category_id, position) VALUES ($relatedId, $targetId, 1)");
$shown = $auth->handle('GET', $slug, [], $origin)->body['data'];
check(str_contains($shown['html'], $imageKey) && str_contains($shown['html'], '/i/Sample'), 'HTML migrado continuou mostrando imagem quebrada.');
check(str_contains($shown['html'], 'class="wiki text-danger"') && count($shown['missing_links']) === 1
    && $shown['missing_links'][0]['slug'] === 'Nao_Cadastrada', 'Link ausente sem card ou destaque.');
check((int) $shown['mentions'][0]['id'] === (int) $relatedId
    && (int) $shown['related_pages'][0]['id'] === (int) $relatedId, 'Cards de menções e páginas relacionadas vazios.');
$shownImage = $auth->handle('GET', '/v1/images/Sample', [], $origin)->body['data'];
check((int) $shownImage['pages'][0]['id'] === (int) $articleId && $shownImage['meta']['width'] === 1
    && count($shownImage['history']) === 4, 'Imagem sem propriedades, vínculos ou histórico.');
$db->exec("UPDATE users SET display_name = 'Editor de teste' WHERE id = 100");
$upload = $auth->handle('PUT', '/v1/images/Esd', [], $origin, ['data' => $a, 'base_version' => ''], $csrf);
check($upload->status === 200 && $upload->body['data']['slug'] === 'Esd', 'Envio inicial da imagem falhou.');
check(str_contains($mailBody, 'href="https://example.test/i/Esd"'), 'E-mail deve apontar para a página da imagem.');
$changedImage = $auth->handle('PUT', '/v1/images/Esd', [], $origin, ['data' => $b, 'base_version' => $upload->body['data']['version']], $csrf);
check($changedImage->status === 200 && $changedImage->body['data']['history'][0]['url'] === $upload->body['data']['url'],
    'Substituição deve preservar o arquivo anterior.');
check(array_slice($subjects, -2) === ['[img][new] Esd', '[img][update] Esd'], 'Envio e substituição devem notificar o admin.');
$oldImage = $changedImage->body['data']['history'][0];
check($oldImage['author'] === 'Editor de teste', 'Histórico deve mostrar o autor do arquivo anterior.');
$restore = $auth->handle('PUT', '/v1/images/Esd', [], $origin,
    ['revision_id' => $oldImage['id'], 'base_version' => $changedImage->body['data']['version']], $csrf);
check($restore->status === 200 && $restore->body['data']['url'] === $upload->body['data']['url']
    && count($restore->body['data']['history']) === 2, 'Restaurar deve preservar a versão substituída.');
$cleared = $auth->handle('PUT', '/v1/images/Esd', [], $origin,
    ['clear' => true, 'base_version' => $restore->body['data']['version']], $csrf);
check($cleared->status === 200 && end($subjects) === '[img][delete] Esd', 'Remoção do arquivo deve notificar o admin.');
check($auth->handle('GET', '/v1/pages/I/editor', [], $origin)->status === 422, 'Endereço de um caractere deve ser reservado.');
$change['source'] = 'Sem vínculos';
$change['base_version'] = $rebuilt->body['data']['base_version'];
check($auth->handle('PUT', $slug, [], $origin, $change, $csrf)->status === 200, 'Remoção dos vínculos falhou.');
foreach (['page_categories', 'page_media', 'page_links', 'page_templates', 'page_headings', 'page_external_links', 'page_videos'] as $table) {
    check((int) $db->query("SELECT COUNT(*) FROM $table WHERE page_id = $articleId")->fetchColumn() === 0, "Vínculos antigos em $table.");
}
$accountService = new MDAWiki\Accounts(new MDAWiki\Database($db), $session, $authConfig,
    static function (string $to, string $subject, string $body) use (&$mailBody): void { $mailBody = $body; });
$accountService->notifyProfile(100);
check(str_contains($mailBody, 'href="https://example.test/u/' . $publicId . '"'), 'E-mail deve apontar para o perfil público do usuário.');
check($auth->handle('POST', '/v1/auth/logout', [], $origin, [], $login->body['data']['csrf_token'])->status === 200, 'Logout migrado falhou.');

$db->exec('USE migration_source');
$page->execute(['abc', 'Abc', 'ABC', 'Conteúdo da categoria no legado', '2026-11-21 10:00:00']);
$db->exec('USE migration_target');
$run(1);
$category = $db->query("SELECT id, source FROM pages WHERE identifier = 'abc'")->fetch(PDO::FETCH_ASSOC);
check((int) $category['id'] === (int) $abc->body['data']['id']
    && $category['source'] === 'Conteúdo da categoria no legado', 'A importação deve reaproveitar o ID da categoria vazia.');

$addRelated = $db->prepare("INSERT INTO pages (legacy_identifier, identifier, slug, title, kind, is_searchable, access_level_id, legacy_checksum)
    VALUES (?, ?, ?, ?, 'article', 1, ?, ?)");
$relate = $db->prepare('INSERT INTO page_categories (page_id, category_id, position) VALUES (?, ?, ?)');
$relate->execute([$articleId, $categoryIds[0], 1]);
$relate->execute([$articleId, $categoryIds[1], 2]);
$publicIds = [];
for ($i = 1; $i <= 8; $i++) {
    $name = 'related-' . $i;
    $addRelated->execute([$name, $name, $name, $name, $i === 8 ? 5 : 1, str_repeat('0', 64)]);
    $id = $db->lastInsertId();
    $relate->execute([$id, $categoryIds[0], 1]);
    $relate->execute([$id, $categoryIds[1], 2]);
    if ($i < 8) $publicIds[] = $id;
}
$related = array_column($normalized->find('Especialidade-Teste')['related_pages'], 'id');
check(count($related) === 5 && count(array_unique($related)) === 5
    && array_diff($related, $publicIds) === [], 'A amostra deve ter cinco páginas relacionadas públicas e distintas.');

$createSlug = '/v1/pages/Novo_artigo';
check($auth->handle('GET', $createSlug . '/editor', [], $origin)->status === 401, 'Editor novo exige autenticação.');
$credentials = ['email' => 'migrated@example.test', 'password' => 'legacy-password'];
$login = $auth->handle('POST', '/v1/auth/login', [], $origin, $credentials, $session->data['csrf']);
check($login->status === 200, 'Login para testar criação falhou.');
$csrf = $login->body['data']['csrf_token'];
$draft = $auth->handle('GET', $createSlug . '/editor', [], $origin);
check($draft->status === 200 && $draft->body['data']['exists'] === false
    && $draft->body['data']['title'] === 'Novo artigo' && $draft->body['data']['source'] === '', 'Editor novo deve abrir vazio.');
check($auth->handle('POST', $createSlug . '/preview', [], $origin, ['source' => 'Prévia de artigo'], $csrf)->status === 200
    && $auth->handle('GET', $createSlug, [], $origin)->status === 404, 'Prévia não deve criar artigo.');
$create = ['title' => 'Novo artigo', 'source' => 'Primeiro conteúdo', 'base_version' => $draft->body['data']['base_version']];
$created = $auth->handle('PUT', $createSlug, [], $origin, $create, $csrf);
check($created->status === 200 && $created->body['data']['slug'] === 'Novo-artigo'
    && $created->body['data']['revision'] === 1, 'Criação deve retornar URL canônica e primeira revisão.');
check($auth->handle('PUT', $createSlug, [], $origin, $create, $csrf)->status === 409, 'Segundo rascunho não deve sobrescrever a página.');
$createdPage = $auth->handle('GET', $createSlug, [], $origin);
check($createdPage->status === 200 && str_contains($createdPage->body['data']['html'], 'Primeiro conteúdo'), 'Artigo salvo deve aparecer na leitura.');

$imageRoute = '/v1/images/RefreshImage';
$gif = base64_decode(explode(',', $a, 2)[1], true);
$originalData = 'data:image/gif;base64,' . base64_encode($gif . 'original');
$newData = 'data:image/gif;base64,' . base64_encode($gif . 'replacement');
$firstImage = $auth->handle('PUT', $imageRoute, [], $origin, ['data' => $originalData, 'base_version' => ''], $csrf);
check($firstImage->status === 200, 'Imagem inicial do teste não foi criada.');
$firstImage = $firstImage->body['data'];
$linkedArticles = ['Imagem-Vinculada-Wiki' => '{{Imagem:RefreshImage}}',
    'Imagem-Vinculada-Html' => '<img src="' . $firstImage['url'] . '">'];
foreach ($linkedArticles as $name => $source) {
    $createdArticle = $auth->handle('PUT', '/v1/pages/' . $name, [], $origin,
        ['title' => $name, 'source' => $source, 'base_version' => ''], $csrf);
    check($createdArticle->status === 200, 'Página vinculada não foi criada.');
}
$swap = $auth->handle('PUT', $imageRoute, [], $origin, ['data' => $newData, 'base_version' => $firstImage['version']], $csrf);
check($swap->status === 200, 'Não foi possível substituir a imagem vinculada.');
$swap = $swap->body['data'];
foreach ($linkedArticles as $name => $source) {
    $stored = $db->query("SELECT rendered_html FROM pages WHERE slug = '$name'")->fetchColumn();
    check(str_contains($stored, $swap['url']) && !str_contains($stored, $firstImage['url']),
        'O HTML salvo da página vinculada ainda aponta para o arquivo antigo.');
}
check(count($swap['pages']) === 2, 'O reprocessamento perdeu vínculos.');
$restoredImage = $auth->handle('PUT', $imageRoute, [], $origin,
    ['revision_id' => $swap['history'][0]['id'], 'base_version' => $swap['version']], $csrf);
check($restoredImage->status === 200, 'Restauração da imagem vinculada falhou.');
foreach ($linkedArticles as $name => $source) {
    $stored = $db->query("SELECT rendered_html FROM pages WHERE slug = '$name'")->fetchColumn();
    check(str_contains($stored, $firstImage['url']), 'A restauração não propagou o arquivo anterior.');
    $history = $auth->handle('GET', '/v1/pages/' . $name . '/history', [], $origin);
    check(count($history->body['data']['revisions']) === 1, 'Atualização de imagem criou revisão da página.');
}

$clear = $auth->handle('PUT', $imageRoute, [], $origin,
    ['clear' => true, 'base_version' => $restoredImage->body['data']['version']], $csrf);
check($clear->status === 200 && $clear->body['data']['url'] === null && count($clear->body['data']['pages']) === 2,
    'Excluir deve deixar a imagem sem arquivo e preservar seus vínculos.');
$cleared = $clear->body['data'];
foreach ($linkedArticles as $name => $source) {
    $stored = $db->query("SELECT rendered_html FROM pages WHERE slug = '$name'")->fetchColumn();
    check(str_contains($stored, '/assets/img_nao_encontrada.svg') && !str_contains($stored, $firstImage['url']),
        'O artigo vinculado deve mostrar o marcador de imagem vazia.');
}
$undo = $auth->handle('PUT', $imageRoute, [], $origin,
    ['revision_id' => $cleared['history'][0]['id'], 'base_version' => $cleared['version']], $csrf);
check($undo->status === 200 && $undo->body['data']['url'] === $firstImage['url'],
    'O arquivo excluído deve permanecer disponível para restauração.');
foreach ($linkedArticles as $name => $source) {
    $stored = $db->query("SELECT rendered_html FROM pages WHERE slug = '$name'")->fetchColumn();
    check(str_contains($stored, $firstImage['url']) && !str_contains($stored, 'img_nao_encontrada'),
        'A restauração após a exclusão deve atualizar novamente os artigos.');
}

$missingName = 'Imagem-Nunca-Cadastrada';
$missingArticle = '/v1/pages/Teste-Imagem-Ausente';
$createdMissing = $auth->handle('PUT', $missingArticle, [], $origin,
    ['title' => 'Teste Imagem Ausente', 'source' => '{{Imagem:' . $missingName . '}}', 'base_version' => ''], $csrf);
check($createdMissing->status === 200, 'O artigo com imagem inexistente deve salvar normalmente.');
check($auth->handle('GET', '/v1/images/' . $missingName, [], $origin)->status === 404, 'O marcador não deve criar uma imagem no banco.');
$article = $auth->handle('GET', $missingArticle, [], $origin)->body['data'];
check(str_contains($article['html'], '/assets/img_nao_encontrada.svg'), 'Imagem nunca cadastrada deve renderizar o marcador.');
$db->prepare('UPDATE pages SET rendered_html = ? WHERE slug = ?')->execute([
    '<figure><img src="{{{url_diretorio}}}@imgs/img_nao_encontrada.png"></figure>', 'Teste-Imagem-Ausente',
]);
$article = $auth->handle('GET', $missingArticle, [], $origin)->body['data'];
check(str_contains($article['html'], '/assets/img_nao_encontrada.svg') && !str_contains($article['html'], 'img_nao_encontrada.png'),
    'O artigo processado anteriormente deve trocar o marcador antigo na leitura.');

$csrf = $auth->handle('GET', '/v1/auth/session', [], $origin)->body['data']['csrf_token'];
$registration = ['name' => 'Conta Nova', 'email' => 'new-normalized@example.test',
    'password' => 'abc123', 'password_confirmation' => 'abc123'];
$lastUserId = (int) $db->query('SELECT MAX(id) FROM users')->fetchColumn();
$register = $auth->handle('POST', '/v1/auth/register', [], $origin, $registration, $csrf);
check($register->status === 200, 'Cadastro na base migrada falhou: ' . json_encode($register->body));
$newUserId = (int) $db->query("SELECT id FROM users WHERE email = 'new-normalized@example.test'")->fetchColumn();
check($newUserId === $lastUserId + 1, 'Cadastro deve continuar a sequência de IDs importados.');
check(preg_match('/code=([0-9]{6})/', $activationMail, $firstCode) === 1, 'Código inicial ausente.');
check($auth->handle('POST', '/v1/auth/register', [], $origin, $registration, $csrf)->status === 409, 'Cadastro duplicado aceito.');
check($auth->handle('POST', '/v1/auth/resend', [], $origin, ['email' => $registration['email']], $csrf)->status === 200, 'Reenvio falhou.');
check(preg_match('/code=([0-9]{6})/', $activationMail, $newCode) === 1 && $newCode[1] !== $firstCode[1], 'Reenvio não gerou outro código.');
check($auth->handle('POST', '/v1/auth/activate', [], $origin, ['code' => $firstCode[1], 'email' => $registration['email']], $csrf)->status === 422, 'Código substituído aceito.');
check($auth->handle('POST', '/v1/auth/activate', [], $origin, ['code' => $newCode[1], 'email' => $registration['email']], $csrf)->status === 200, 'Ativação falhou.');
check($auth->handle('POST', '/v1/auth/activate', [], $origin, ['code' => $newCode[1], 'email' => $registration['email']], $csrf)->status === 422, 'Código consumido foi aceito.');
$firstLogin = $auth->handle('POST', '/v1/auth/login', [], $origin, $registration, $csrf);
check($firstLogin->status === 200 && $firstLogin->body['data']['first_login'] === true, 'Primeiro login deve sinalizar acesso ao perfil.');
$csrf = $firstLogin->body['data']['csrf_token'];
$profileUpdate = $auth->handle('PUT', '/v1/profile', [], $origin, ['CIDADE' => 'Campinas', 'ENVIA_EMAIL' => 'N'], $csrf);
check($profileUpdate->status === 200 && $profileUpdate->body['data']['CIDADE'] === 'Campinas'
    && $profileUpdate->body['data']['ENVIA_EMAIL'] === 'N', 'Perfil migrado deve aceitar atualizações.');
$secondLogin = $auth->handle('POST', '/v1/auth/login', [], $origin, $registration, $csrf);
check($secondLogin->status === 200 && $secondLogin->body['data']['first_login'] === false, 'Segundo login não deve repetir a sinalização.');

$csrf = $secondLogin->body['data']['csrf_token'];
$recorded = $auth->handle('POST', '/v1/visits', [], $origin, ['slug' => 'EspéciAlidade-Teste'], $csrf);
check($recorded->status === 200 && $recorded->body['data']['counted'] === true, 'Visita pública deve ser contada.');
$duplicate = $auth->handle('POST', '/v1/visits', [], $origin, ['slug' => 'EspéciAlidade-Teste'], $csrf);
check($duplicate->status === 200 && $duplicate->body['data']['counted'] === false, 'Visita repetida na sessão deve ser ignorada.');

$db->prepare('INSERT INTO page_visits (page_id, visited_at) VALUES (?, ?)')->execute([$articleId, time() - 86401]);
$db->prepare('INSERT INTO page_media (page_id, image_id, position, is_template) VALUES (?, ?, 1, 0)')->execute([$articleId, $imageId]);
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
check((int) $home['top_24h'][0]['views'] === 1 && (int) $home['top_week'][0]['views'] === 2, 'Visita antiga só deve contar no ranking semanal.');
check(str_contains($home['top_24h'][0]['image'] ?? '', '/media/articles/'), 'O card deve receber a URL da imagem vinculada.');
$db->prepare('UPDATE images SET access_level_id = 5 WHERE id = ?')->execute([$imageId]);
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
check($home['top_24h'][0]['image'] === null, 'O card público deve ocultar imagens restritas.');

$db->prepare('UPDATE pages SET is_searchable = 0 WHERE id <> ?')->execute([$articleId]);
$insert = $db->prepare("INSERT INTO pages (legacy_identifier, identifier, slug, title, kind, source, is_searchable, access_level_id, legacy_checksum, view_count)
    VALUES (?, ?, ?, ?, 'article', ?, ?, 1, ?, 100)");
foreach ([['Sem-Conteudo', " \n\t ", 1], ['Fora-Da-Busca', 'Texto de teste', 0]] as [$title, $source, $searchable]) {
    $identifier = strtolower($title);
    $insert->execute([$identifier, $identifier, $title, $title, $source, $searchable, str_repeat('0', 64)]);
    $db->prepare('INSERT INTO page_visits (page_id, visited_at) VALUES (?, ?)')->execute([$db->lastInsertId(), time()]);
}
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
foreach (['random', 'popular', 'top_24h', 'top_week', 'top_month'] as $section) {
    check(array_column($home[$section], 'id') === [(string) $articleId],
        "O bloco {$section} deve exibir apenas artigos buscáveis com conteúdo.");
}
