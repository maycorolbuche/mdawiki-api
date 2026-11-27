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
$db->exec('CREATE DATABASE normalized_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE normalized_test');
$schema = file_get_contents(__DIR__ . '/../migrations/001_database.sql');
$db->exec($schema);

$a = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
$gif = base64_decode(explode(',', $a, 2)[1], true);
$b = 'data:image/gif;base64,' . base64_encode($gif . "\0");
$root = sys_get_temp_dir() . '/mdawiki-normalized-' . bin2hex(random_bytes(8));
mkdir($root . '/articles', 0700, true);
mkdir($root . '/users', 0700);
$hash = hash('sha256', $gif);
$key = 'articles/' . $hash . '.gif';
$avatar = $hash . '.gif';
file_put_contents($root . '/' . $key, $gif);
file_put_contents($root . '/users/' . $avatar, $gif);
$publicId = str_repeat('a', 32);
$db->prepare("INSERT INTO users (id, email, name, password_hash, is_active, public_id, avatar_filename)
    VALUES (100, 'migrated@example.test', 'Editor', ?, 1, ?, ?)")->execute([md5('legacy-password'), $publicId, $avatar]);
$db->prepare("INSERT INTO files (id, sha256, storage_key, mime_type, size_bytes) VALUES (1, ?, ?, 'image/gif', ?)")
    ->execute([$hash, $key, strlen($gif)]);
$db->exec("INSERT INTO images (identifier, slug, title, meta, file_id, legacy_identifier, access_level_id, created_by, created_at)
    VALUES ('sample', 'Sample', 'Imagem', '{\"width\":1}', 1, 'imagem@sample', 1, 100, '2020-01-01')");
$db->exec("INSERT INTO image_revisions (image_id, file_id, created_by, created_at)
    VALUES (1, 1, 100, '2020-01-01'), (1, 1, 100, '2020-01-02'), (1, 1, 100, '2020-01-03'), (1, 1, 100, '2020-01-04')");
$db->exec("INSERT INTO pages (id, legacy_identifier, identifier, slug, title, source, rendered_html, is_searchable, kind)
    VALUES (1, 'article', 'especialidade-teste', 'EspéciAlidade-Teste', 'EspéciAlidade Teste', 'Editado', '<p>Editado</p>', 1, 'article')");
$importedPage = ['id' => 1];
$db->exec($schema);
check($db->query('SELECT role FROM users WHERE id = 100')->fetchColumn() === 'user',
    'O schema deve preservar a conta existente e usar o papel user por padrão.');
check($db->query('SELECT source FROM pages WHERE id = 1')->fetchColumn() === 'Editado',
    'Reaplicar o schema não deve apagar páginas.');
$db->exec(file_get_contents(__DIR__ . '/../migrations/002_drop_pages_legacy_identifier.sql'));
require dirname(__DIR__) . '/config/bootstrap.php';
$normalized = new MDAWiki\NormalizedPageRepository(new MDAWiki\Database($db), 1, 'https://api.example.test/media');
check($normalized->find('article') === null, 'Resolver páginas apenas pelo identificador atual.');
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

$db->exec("UPDATE pages SET is_searchable = 0 WHERE id = 1");
check($preview->handle('GET', '/v1/pages/Especialidade-Teste', [])->status === 404, 'Prévia expôs artigo fora da busca.');

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
        'association', 'club_type', 'biography', 'photo', 'edited_pages', 'next_edits_offset'], 'Perfil público expôs campos privados ou perdeu o avatar.');
$initial = $auth->handle('GET', '/v1/auth/session', [], $origin);
$credentials = ['email' => 'migrated@example.test', 'password' => 'legacy-password'];
check($auth->handle('POST', '/v1/auth/login', [], $origin, $credentials, 'wrong')->status === 403, 'Login ignorou CSRF.');
$login = $auth->handle('POST', '/v1/auth/login', [], $origin, $credentials, $initial->body['data']['csrf_token']);
check($login->status === 200 && (int) $login->body['data']['user']['ID'] === 100, 'Login do usuário migrado falhou.');
check($login->body['data']['user']['photo'] === $publicUser->body['data']['photo'], 'Avatar migrado não apareceu na sessão.');
check($auth->handle('GET', '/v1/images/Sample', [], $origin)->status === 200, 'Imagem migrada indisponível.');
$db->exec("UPDATE pages SET is_searchable = 1 WHERE id = 1");
$slug = '/v1/pages/Especialidade_Teste';
$public = $auth->handle('GET', $slug, [], $origin);
check($public->status === 200 && $public->body['data']['read_only'] === false, 'Botões do artigo ficaram ocultos.');
$editor = $auth->handle('GET', $slug . '/editor', [], $origin);
check($editor->status === 200 && $editor->body['data']['source'] === 'Editado', 'Editor não abriu o artigo migrado.');
$csrf = $login->body['data']['csrf_token'];
$change = ['title' => 'EspéciAlidade Teste', 'source' => 'Texto novo', 'base_version' => $editor->body['data']['base_version']];
check($auth->handle('POST', $slug . '/preview', [], $origin, $change, $csrf)->status === 200, 'Prévia do artigo falhou.');
$db->exec("UPDATE users SET role = 'reader' WHERE id = 100");
check($auth->handle('GET', $slug, [], $origin)->status === 200, 'Leitor deve poder acessar o artigo.');
check($auth->handle('GET', $slug . '/editor', [], $origin)->status === 403, 'Leitor abriu o editor.');
check($auth->handle('PUT', $slug, [], $origin, $change, $csrf)->status === 403, 'Leitor alterou o artigo.');
check($auth->handle('POST', $slug . '/restore', [], $origin, ['revision' => 1], $csrf)->status === 403, 'Leitor restaurou uma revisão.');
$db->exec("UPDATE users SET role = 'user' WHERE id = 100");
$saved = $auth->handle('PUT', $slug, [], $origin, $change, $csrf);
check($saved->status === 200, 'Salvamento do artigo migrado falhou.');
check(end($subjects) === '[page][update] EspéciAlidade Teste', 'Alteração do artigo deve notificar o admin.');
check($auth->handle('PUT', $slug, [], $origin, $change, $csrf)->status === 409, 'Versão desatualizada sobrescreveu artigo.');
check(str_contains($mailBody, 'href="https://example.test/Esp%C3%A9ciAlidade-Teste"'), 'E-mail deve usar o endereço canônico do artigo.');
$history = $auth->handle('GET', $slug . '/history', [], $origin);
check($history->status === 200 && $history->body['data']['revisions'][0]['source'] === 'Texto novo', 'Revisão salva não consta no histórico.');
$db->exec("UPDATE users SET role = 'admin' WHERE id = 100");
$imageId = $db->query('SELECT id FROM images')->fetchColumn();
$articleId = $importedPage['id'];
$change = [
    'title' => 'EspéciAlidade Teste',
    'source' => "{{Categorias:Imagem, IMAGEM}}{{Categorias:Estudos da Natureza, abc, ÁBC}}\n{{Imagem:Sample}}\n{{Video:https://example.test/video|Legenda}}\n==Seção==\n[[Imagem#Detalhes|Ver imagem]] [[https://example.test/|Site]]",
    'base_version' => $history->body['data']['base_version'],
];
$saved = $auth->handle('PUT', $slug, [], $origin, $change, $csrf);
check($saved->status === 200, 'Salvamento com vínculos falhou.');
$contributions = $auth->handle('GET', '/v1/public-users/' . $publicId, [], $origin)->body['data']['edited_pages'];
check(count(array_filter($contributions, static fn ($page) => $page['slug'] === 'EspéciAlidade-Teste')) === 1,
    'O perfil deve listar uma única vez o artigo público com várias revisões do usuário.');
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
foreach (['page_markup_index', 'page_headings', 'page_external_links', 'page_videos'] as $table) {
    check((int) $db->query("SELECT COUNT(*) FROM $table WHERE page_id = $articleId")->fetchColumn() > 0,
        "Salvamento não preencheu $table.");
}
$db->exec("DELETE FROM page_categories WHERE page_id = $articleId");
$db->exec("UPDATE pages SET rendered_html = 'desatualizado' WHERE id = $articleId");
$db->exec("INSERT INTO page_rebuild_queue (page_id) VALUES ($articleId)");
$queue = $auth->handle('GET', '/v1/catalog', ['type' => 'atualizar'], $origin);
check($queue->status === 200 && (int) $queue->body['data']['items'][0]['id'] === (int) $articleId, 'Página pendente ausente da fila.');
$db->prepare('INSERT INTO page_includes (page_id, target_page_id, position) VALUES (?, ?, 0), (?, ?, 1)')
    ->execute([$targetId, $articleId, $targetId, $articleId]);
$beforeRebuild = count($subjects);
$rebuilt = $auth->handle('POST', $slug . '/rebuild', [], $origin, [], $csrf);
check($rebuilt->status === 200 && $rebuilt->body['data'] === $saved->body['data'], 'Atualizar alterou a revisão ou versão de edição.');
check((int) $db->query("SELECT COUNT(*) FROM page_revisions WHERE page_id = $articleId")->fetchColumn() === 2, 'Atualizar criou histórico.');
check($db->query("SELECT category_id FROM page_categories WHERE page_id = $articleId ORDER BY position")->fetchAll(PDO::FETCH_COLUMN) === $expectedCategories, 'Atualizar não refez categorias.');
check($db->query("SELECT rendered_html FROM pages WHERE id = $articleId")->fetchColumn() !== 'desatualizado', 'Atualizar não refez HTML.');
check((int) $db->query("SELECT COUNT(*) FROM page_rebuild_queue WHERE page_id = $articleId")->fetchColumn() === 0, 'Reprocessamento não removeu a pendência.');
check($db->query("SELECT page_id FROM page_rebuild_queue")->fetchAll(PDO::FETCH_COLUMN) === [$targetId],
    'Páginas dependentes devem entrar na fila uma única vez.');
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
check(count($upload->body['data']['history']) === 1 && $upload->body['data']['history'][0]['url'] === $upload->body['data']['url'],
    'O cadastro deve registrar a primeira revisão com o arquivo atual.');
$gallery = $auth->handle('GET', '/v1/admin/images', ['sort' => 'size', 'direction' => 'asc'], $origin);
check($gallery->status === 200 && in_array('Esd', array_column($gallery->body['data']['items'], 'slug'), true),
    'A galeria administrativa deve incluir a imagem criada.');
$galleryImage = array_values(array_filter($gallery->body['data']['items'], static fn ($item) => $item['slug'] === 'Esd'))[0];
$original = $auth->handle('GET', '/v1/admin/images', ['original' => $galleryImage['id']], $origin);
check($original->status === 200 && $original->rawBody === $gif && $original->headers['Content-Type'] === 'image/gif',
    'O visualizador administrativo deve retornar os bytes originais.');
check(str_contains($mailBody, 'href="https://example.test/i/Esd"'), 'E-mail deve apontar para a página da imagem.');
$changedImage = $auth->handle('PUT', '/v1/images/Esd', [], $origin, ['data' => $b, 'base_version' => $upload->body['data']['version']], $csrf);
check($changedImage->status === 200 && count($changedImage->body['data']['history']) === 2 && $changedImage->body['data']['history'][1]['url'] === $upload->body['data']['url'],
    'Substituição deve preservar o arquivo anterior.');
check(array_slice($subjects, -2) === ['[img][new] Esd', '[img][update] Esd'], 'Envio e substituição devem notificar o admin.');
$oldImage = $changedImage->body['data']['history'][1];
check($oldImage['author'] === 'Editor de teste', 'Histórico deve mostrar o autor do arquivo anterior.');
$restore = $auth->handle('PUT', '/v1/images/Esd', [], $origin,
    ['revision_id' => $oldImage['id'], 'base_version' => $changedImage->body['data']['version']], $csrf);
check($restore->status === 200 && $restore->body['data']['url'] === $upload->body['data']['url']
    && count($restore->body['data']['history']) === 3, 'Restaurar deve preservar a versão substituída.');
$cleared = $auth->handle('PUT', '/v1/images/Esd', [], $origin,
    ['clear' => true, 'base_version' => $restore->body['data']['version']], $csrf);
check($cleared->status === 200 && end($subjects) === '[img][delete] Esd', 'Remoção do arquivo deve notificar o admin.');
check($auth->handle('GET', '/v1/pages/I/editor', [], $origin)->status === 422, 'Endereço de um caractere deve ser reservado.');
$change['source'] = 'Sem vínculos';
$change['categories'] = [];
$change['base_version'] = $rebuilt->body['data']['base_version'];
check($auth->handle('PUT', $slug, [], $origin, $change, $csrf)->status === 200, 'Remoção dos vínculos falhou.');
foreach (['page_categories', 'page_media', 'page_links', 'page_markup_index', 'page_headings', 'page_external_links', 'page_videos'] as $table) {
    check((int) $db->query("SELECT COUNT(*) FROM $table WHERE page_id = $articleId")->fetchColumn() === 0, "Vínculos antigos em $table.");
}
$accountService = new MDAWiki\Accounts(new MDAWiki\Database($db), $session, $authConfig,
    static function (string $to, string $subject, string $body) use (&$mailBody): void { $mailBody = $body; });
$accountService->notifyProfile(100);
check(str_contains($mailBody, 'href="https://example.test/u/' . $publicId . '"'), 'E-mail deve apontar para o perfil público do usuário.');
check($auth->handle('POST', '/v1/auth/logout', [], $origin, [], $login->body['data']['csrf_token'])->status === 200, 'Logout migrado falhou.');

$addRelated = $db->prepare("INSERT INTO pages (identifier, slug, title, kind, is_searchable)
    VALUES (?, ?, ?, 'article', ?)");
$relate = $db->prepare('INSERT INTO page_categories (page_id, category_id, position) VALUES (?, ?, ?)');
$relate->execute([$articleId, $categoryIds[0], 1]);
$relate->execute([$articleId, $categoryIds[1], 2]);
$publicIds = [];
for ($i = 1; $i <= 8; $i++) {
    $name = 'related-' . $i;
    $addRelated->execute([$name, $name, $name, $i === 8 ? 0 : 1]);
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
    ['revision_id' => $swap['history'][1]['id'], 'base_version' => $swap['version']], $csrf);
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

$newImage = $auth->handle('PUT', '/v1/images/' . $missingName, [], $origin,
    ['data' => $originalData, 'base_version' => ''], $csrf);
check($newImage->status === 200, 'A imagem criada depois do artigo deve ser salva.');
check(in_array('Teste-Imagem-Ausente', array_column($newImage->body['data']['pages'], 'slug'), true),
    'O artigo deve aparecer entre as páginas vinculadas logo após criar a imagem.');
$article = $auth->handle('GET', $missingArticle, [], $origin)->body['data'];
check(str_contains($article['html'], $newImage->body['data']['url']) && !str_contains($article['html'], 'img_nao_encontrada'),
    'O HTML armazenado deve trocar o marcador pela imagem nova.');
$history = $auth->handle('GET', $missingArticle . '/history', [], $origin);
check(count($history->body['data']['revisions']) === 1, 'Vincular a imagem nova não deve gerar uma revisão do artigo.');

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
$profileUpdate = $auth->handle('PUT', '/v1/profile', [], $origin, ['CIDADE' => 'Campinas', 'ENVIA_EMAIL' => 'N', 'role' => 'admin'], $csrf);
check($profileUpdate->status === 200 && $profileUpdate->body['data']['CIDADE'] === 'Campinas'
    && !array_key_exists('ENVIA_EMAIL', $profileUpdate->body['data'])
    && $profileUpdate->body['data']['role'] === 'user',
    'Perfil deve atualizar os dados sem expor a preferência removida ou alterar o papel.');
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
$article = $auth->handle('GET', '/v1/pages/EspéciAlidade-Teste', [])->body['data'];
check($article['image'] === $home['top_24h'][0]['image'], 'O artigo e a home devem usar a mesma imagem.');
$export = $auth->handle('GET', '/v1/pages', ['limit' => 1])->body['data'];
check($export[0]['image'] === $article['image'], 'O build estático deve receber a imagem do artigo.');
$db->prepare('UPDATE images SET access_level_id = 5 WHERE id = ?')->execute([$imageId]);
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
check($home['top_24h'][0]['image'] === null, 'O card público deve ocultar imagens restritas.');

$db->prepare('UPDATE pages SET is_searchable = 0 WHERE id <> ?')->execute([$articleId]);
$insert = $db->prepare("INSERT INTO pages (identifier, slug, title, kind, source, is_searchable, view_count)
    VALUES (?, ?, ?, 'article', ?, ?, 100)");
foreach ([['Sem-Conteudo', " \n\t ", 1], ['Fora-Da-Busca', 'Texto de teste', 0]] as [$title, $source, $searchable]) {
    $identifier = strtolower($title);
    $insert->execute([$identifier, $title, $title, $source, $searchable]);
    $db->prepare('INSERT INTO page_visits (page_id, visited_at) VALUES (?, ?)')->execute([$db->lastInsertId(), time()]);
}
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
foreach (['random', 'recent', 'popular', 'top_24h', 'top_week', 'top_month'] as $section) {
    check(array_column($home[$section], 'id') === [(string) $articleId],
        "O bloco {$section} deve exibir apenas artigos buscáveis com conteúdo.");
}

$csrf = $secondLogin->body['data']['csrf_token'];
$originPage = $auth->handle('PUT', '/v1/pages/Origem-Dos-Links', [], $origin, [
    'title' => 'Origem dos links', 'source' => '[[Destino Ausente#Seção|Sugestão]] [[Déstino-ausente|Outro texto]]',
    'base_version' => '',
], $csrf);
check($originPage->status === 200, 'Página de origem deveria ser salva.');
check((int) $db->query("SELECT COUNT(*) FROM page_missing_links WHERE href = '/destino-ausente'")->fetchColumn() === 2,
    'Cada ocorrência deve manter sua posição e o mesmo destino normalizado.');
$home = $auth->handle('GET', '/v1/home', [], $origin)->body['data'];
check(array_count_values(array_column($home['missing'], 'slug'))['destino-ausente'] === 1, 'A home deve deduplicar por destino.');
$destination = $auth->handle('PUT', '/v1/pages/Destino-Ausente', [], $origin, [
    'title' => 'Destino Ausente', 'source' => 'Conteúdo novo', 'base_version' => '',
], $csrf);
check($destination->status === 200, 'Destino deveria ser criado.');
check((int) $db->query("SELECT COUNT(*) FROM page_missing_links WHERE href = '/destino-ausente'")->fetchColumn() === 0,
    'A criação do destino deve remover os vínculos pendentes.');
check((int) $db->query("SELECT COUNT(*) FROM page_links l JOIN pages p ON p.id = l.target_page_id WHERE p.identifier = 'destino-ausente'")->fetchColumn() === 2,
    'Os vínculos pendentes devem migrar para page_links.');

$adminRoute = '/v1/admin/pages';
$destinationId = (int) $db->query("SELECT id FROM pages WHERE identifier = 'destino-ausente'")->fetchColumn();
check($auth->handle('GET', $adminRoute, [], $origin)->status === 403, 'Admin pages must reject non-admin accounts.');
$db->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$secondLogin->body['data']['user']['ID']]);
$adminPages = $auth->handle('GET', $adminRoute, ['q' => 'Destino Ausente', 'content' => 'yes'], $origin);
check($adminPages->status === 200 && (int) $adminPages->body['data']['items'][0]['characters'] === 13,
    'Administrative page search must count Unicode characters.');
$emptyPages = $auth->handle('GET', $adminRoute, ['q' => 'Sem-Conteudo', 'content' => 'no'], $origin);
check($emptyPages->status === 200 && $emptyPages->body['data']['total'] === 1, 'Whitespace-only pages must be empty.');
check($auth->handle('POST', $adminRoute, [], $origin, ['id' => $destinationId], 'invalid')->status === 403,
    'Hard deletion must require CSRF.');
check($auth->handle('POST', $adminRoute, [], $origin, ['id' => $destinationId], $csrf)->status === 200,
    'An administrator must be able to delete the page and its history.');
check((int) $db->query("SELECT COUNT(*) FROM pages WHERE id = $destinationId")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM page_revisions WHERE page_id = $destinationId")->fetchColumn() === 0,
    'Hard deletion must remove the page and its revisions.');
check((int) $db->query("SELECT COUNT(*) FROM page_missing_links WHERE href = '/destino-ausente'")->fetchColumn() === 2,
    'Links from surviving pages must retain their missing destination.');

$categoryPage = '/v1/pages/Editor-De-Categorias';
$initial = $auth->handle('PUT', $categoryPage, [], $origin, [
    'title' => 'Editor de categorias',
    'source' => '{{Categorias:Categoria Histórica}}Texto',
    'base_version' => '',
], $csrf);
check($initial->status === 200, 'Editor antigo deve poder salvar as categorias no texto.');
$editor = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check($editor['source'] === 'Texto' && array_column($editor['categories'], 'title') === ['Categoria Histórica'],
    'O editor deve separar categorias e texto legado.');
check(in_array('Categoria Histórica', array_column($editor['category_options'], 'title'), true),
    'O editor deve sugerir categorias existentes.');
$categorized = $auth->handle('PUT', $categoryPage, [], $origin, [
    'title' => $editor['title'], 'source' => $editor['source'],
    'categories' => ['Categoria Nova', 'Categória-Nova'], 'base_version' => $editor['base_version'],
], $csrf);
check($categorized->status === 200, 'O editor deve permitir criar categorias e deduplicar suas variantes.');
$updated = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check(array_column($updated['categories'], 'title') === ['Categoria Nova'], 'Categoria nova não foi vinculada.');
$rebuilt = $auth->handle('POST', $categoryPage . '/rebuild', [], $origin, [], $csrf);
check($rebuilt->status === 200, 'Reprocessamento de artigo com vínculo independente falhou.');
$updated = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check(array_column($updated['categories'], 'title') === ['Categoria Nova'], 'Rebuild apagou categorias editadas.');
$cleared = $auth->handle('PUT', $categoryPage, [], $origin, [
    'title' => $updated['title'], 'source' => $updated['source'],
    'categories' => [], 'base_version' => $updated['base_version'],
], $csrf);
check($cleared->status === 200 && $cleared->body['data']['revision'] === $categorized->body['data']['revision'] + 1,
    'Alterar somente categorias deve criar uma revisão.');
$restored = $auth->handle('POST', $categoryPage . '/restore', [], $origin, [
    'revision' => $categorized->body['data']['revision'], 'base_version' => $cleared->body['data']['base_version'],
], $csrf);
check($restored->status === 200, 'Restauração da revisão com categorias falhou.');
$updated = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check(array_column($updated['categories'], 'title') === ['Categoria Nova'], 'A restauração deve recuperar as categorias da revisão.');
$omitted = $auth->handle('PUT', $categoryPage, [], $origin, [
    'title' => $updated['title'], 'source' => 'Texto atualizado',
    'base_version' => $updated['base_version'],
], $csrf);
check($omitted->status === 200, 'Salvar apenas o texto deve continuar funcionando.');
$updated = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check(array_column($updated['categories'], 'title') === ['Categoria Nova'],
    'Omitir categorias deve preservar os vínculos já gravados.');

$categoryHistory = $auth->handle('GET', $categoryPage . '/history', [], $origin)->body['data']['revisions'];
$byRevision = array_column($categoryHistory, null, 'id');
check($byRevision[$categorized->body['data']['revision']]['categories'] === [
    ['key' => 'categoria-nova', 'title' => 'Categoria Nova'],
], 'O histórico deve mostrar as categorias da revisão, mesmo após alterações.');
check($byRevision[$cleared->body['data']['revision']]['categories'] === [],
    'O histórico deve preservar uma lista de categorias vazia.');
$db->exec("UPDATE page_revisions r JOIN pages p ON p.id = r.page_id
    SET r.category_ids = NULL WHERE p.identifier = 'editor-de-categorias' AND r.revision_number = 1");
$legacyHistory = $auth->handle('GET', $categoryPage . '/history', [], $origin)->body['data']['revisions'];
$legacyRevision = array_column($legacyHistory, null, 'id')[1];
check($legacyRevision['source'] === 'Texto' && $legacyRevision['categories'] === [
    ['key' => 'categoria-historica', 'title' => 'Categoria Histórica'],
], 'O histórico deve separar a marcação de categorias nas revisões legadas.');

$migrationPageId = (int) $db->query("SELECT id FROM pages WHERE identifier = 'editor-de-categorias'")->fetchColumn();
$db->prepare('UPDATE pages SET source = ? WHERE id = ?')
    ->execute(["Antes\r\n{{Categorias:Atividades do Acervo}}\r\nDepois{{Categoria:}}", $migrationPageId]);
$revisionMetadata = "SELECT id, revision_number, created_by, created_at FROM page_revisions
    WHERE page_id = $migrationPageId ORDER BY id";
$beforeMetadata = $db->query($revisionMetadata)->fetchAll(PDO::FETCH_ASSOC);
$db->exec("UPDATE page_revisions SET category_ids = NULL WHERE page_id = $migrationPageId AND revision_number = 3");
$migrationDb = new MDAWiki\Database($db);
check(MDAWiki\NormalizedCategoryMigration::run($migrationDb) === 0, 'A migração de categorias deve terminar sem falhas.');
$migratedEditor = $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'];
check($migratedEditor['source'] === "Antes\r\n\r\nDepois"
    && array_column($migratedEditor['categories'], 'title') === ['Categoria Nova', 'Atividades do Acervo'],
    'A migração deve manter o texto restante e combinar os vínculos atuais e legados.');
$migratedRows = $db->query("SELECT revision_number, source, category_ids FROM page_revisions
    WHERE page_id = $migrationPageId ORDER BY revision_number")->fetchAll(PDO::FETCH_ASSOC);
$historicalCategoryId = (string) $db->query("SELECT id FROM pages WHERE identifier = 'categoria-historica'")->fetchColumn();
check($migratedRows[0]['source'] === 'Texto'
    && json_decode($migratedRows[0]['category_ids'], true) === [$historicalCategoryId],
    'A migração deve persistir as categorias extraídas da própria revisão antiga.');
check($migratedRows[2]['category_ids'] === '[]', 'Revisões legadas sem categorias devem receber a lista vazia.');
check($beforeMetadata === $db->query($revisionMetadata)->fetchAll(PDO::FETCH_ASSOC),
    'A migração deve preservar os IDs, autores e datas do histórico.');
check(MDAWiki\NormalizedCategoryMigration::run($migrationDb) === 0, 'A migração deve poder ser repetida.');
check($migratedRows === $db->query("SELECT revision_number, source, category_ids FROM page_revisions
    WHERE page_id = $migrationPageId ORDER BY revision_number")->fetchAll(PDO::FETCH_ASSOC)
    && $migratedEditor === $auth->handle('GET', $categoryPage . '/editor', [], $origin)->body['data'],
    'Reexecutar a migração deve manter os vínculos e as revisões já convertidos.');

$templatePage = '/v1/pages/Modelo-Estruturado';
$personTemplate = ['template' => 'person', 'meta' => ['display_name' => 'Pessoa de Teste',
    'positions' => [['title' => 'Cargo A', 'start_date' => '2001', 'end_date' => '2003'], ['title' => 'Cargo B']]]];
$honorTemplate = ['template' => 'adventurer_honor', 'meta' => ['area_code' => 'AM', 'code' => '015', 'icon' => 'Sample', 'is_obsolete' => true]];
$models = [$personTemplate, $honorTemplate];
$draft = ['source' => 'Texto sem marcações', 'templates' => $models];
$previewed = $auth->handle('POST', $templatePage . '/preview', [], $origin, $draft, $csrf);
check($previewed->status === 200 && str_contains($previewed->body['data']['html'], 'Cargo B')
    && str_contains($previewed->body['data']['html'], '2001 a 2003'), 'A prévia deve renderizar todos os cargos.');
$created = $auth->handle('PUT', $templatePage, [], $origin, $draft + ['title' => 'Modelo', 'base_version' => ''], $csrf);
check($created->status === 200, 'A página deve aceitar templates de tipos diferentes.');
$article = $auth->handle('GET', $templatePage, [], $origin)->body['data'];
check(str_contains($article['html'], '015') && str_contains($article['html'], 'Descontinuada')
    && str_contains($article['html'], 'Cargo A'), 'Os templates devem ser publicados com o artigo.');
$templateEditor = $auth->handle('GET', $templatePage . '/editor', [], $origin)->body['data'];
check($templateEditor['source'] === $draft['source']
    && array_column($templateEditor['templates'], 'template') === ['adventurer_honor', 'person'],
    'O editor deve receber o texto original e os templates na ordem definida.');
$duplicate = $auth->handle('PUT', $templatePage, [], $origin,
    ['title' => 'Modelo', 'source' => $draft['source'], 'base_version' => $templateEditor['base_version'],
        'templates' => [$honorTemplate, $honorTemplate]], $csrf);
check($duplicate->status === 422, 'A API deve rejeitar dois cards do mesmo tipo.');
$removed = $auth->handle('PUT', $templatePage, [], $origin, [
    'title' => 'Modelo', 'source' => $draft['source'], 'templates' => [],
    'base_version' => $templateEditor['base_version'],
], $csrf);
check($removed->status === 200 && $removed->body['data']['revision'] === 2, 'Remover somente os templates deve gerar revisão.');
$templateHistory = $auth->handle('GET', $templatePage . '/history', [], $origin)->body['data']['revisions'];
check(count($templateHistory[1]['templates']) === 2 && $templateHistory[0]['templates'] === [], 'O histórico deve manter snapshots dos templates.');
$templateRestore = $auth->handle('POST', $templatePage . '/restore', [], $origin, [
    'revision' => 1, 'base_version' => $removed->body['data']['base_version'],
], $csrf);
check($templateRestore->status === 200
    && count($auth->handle('GET', $templatePage . '/editor', [], $origin)->body['data']['templates']) === 2,
    'A restauração deve recuperar todos os cards.');
