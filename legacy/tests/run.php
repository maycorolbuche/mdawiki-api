<?php
declare(strict_types=1);

use MDAWiki\Application;
use MDAWiki\LegacyPageRepository;
use MDAWiki\PageRepository;

require dirname(__DIR__) . '/config/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repo = new class implements PageRepository {
    public function list(int $limit, string $after): array
    {
        return array_slice(array_values(array_filter([
            ['id' => 'a', 'slug' => 'Especialidade_de_Cães', 'title' => 'Cães', 'html' => '<a href="{{{url_site}}}Artigo">Artigo</a>', 'text' => 'Texto da página'],
            ['id' => 'b', 'slug' => 'Outro', 'title' => 'Outro', 'html' => '<p>Outro</p>'],
        ], static fn ($row) => $row['id'] > $after)), 0, $limit);
    }

    public function find(string $slug): ?array
    {
        foreach ($this->list(100, '') as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }
        return null;
    }
};
$config = ['origins' => ['https://mda.wiki.br'], 'frontend_url' => 'https://mda.wiki.br', 'media_url' => 'https://legacy.mda.wiki.br/site'];
$app = new Application(static fn () => $repo, $config);
$lanOrigin = 'http://192.168.56.1:5173';
$local = new Application(static fn () => $repo, ['app_env' => 'local'] + $config);
foreach (['GET', 'OPTIONS'] as $method) {
    $response = $local->handle($method, '/v1/pages', [], $lanOrigin);
    check($response->status === ($method === 'OPTIONS' ? 204 : 200), 'Local LAN origin accepted');
    check($response->headers['Access-Control-Allow-Origin'] === $lanOrigin, 'Local origin echoed');
    check($response->headers['Access-Control-Allow-Credentials'] === 'true', 'Local credentials supported');
}
check($local->handle('GET', '/v1/pages', [], 'https://evil.test')->status === 403, 'Local public origin still requires allowlist');
check($app->handle('OPTIONS', '/v1/pages', [], $lanOrigin)->status === 403, 'Production LAN preflight requires allowlist');
check($app->handle('GET', '/health', [])->status === 200, 'Health must work without database');
$page = $app->handle('GET', '/v1/pages/Especialidade_de_C%C3%A3es', []);
check($page->status === 200 && $page->body['data']['title'] === 'Cães', 'Legacy accented slug');
check(str_contains($page->body['data']['html'], 'https://mda.wiki.br/Artigo'), 'URL placeholder');
check(!isset($page->body['data']['text']), 'Do not expose storage columns');
$first = $app->handle('GET', '/v1/pages', ['limit' => '1']);
check(count($first->body['data']) === 1 && $first->body['next_cursor'] === 'a', 'Pagination cursor');
$second = $app->handle('GET', '/v1/pages', ['limit' => '1', 'after' => 'a']);
check($second->body['next_cursor'] === null && $second->body['data'][0]['id'] === 'b', 'Last page');
check($app->handle('GET', '/v1/pages', ['limit' => '501'])->status === 400, 'Bound export size');
check($app->handle('GET', '/v1/pages', ['after' => ['sql']])->status === 400, 'Reject array cursor');
check($app->handle('GET', '/v1/pages/Missing', [])->status === 404, 'Missing article');
check($app->handle('GET', '/v1/pages/a%2Fb', [])->status === 400, 'Reject slash in slug');
check($app->handle('POST', '/v1/pages', [])->status === 405, 'Read-only foundation');
check($app->handle('OPTIONS', '/v1/pages', [], 'https://mda.wiki.br')->status === 204, 'Allowed preflight');
check($app->handle('GET', '/v1/pages', [], 'https://evil.test')->status === 403, 'Reject unlisted CORS origin');
check($app->handle('GET', '/v1/pages', [], 'https://mda.wiki.br')->headers['Access-Control-Allow-Origin'] === 'https://mda.wiki.br', 'Explicit origin');
$broken = new Application(static fn () => throw new RuntimeException('DB unavailable'), $config);
check($broken->handle('GET', '/v1/pages', [])->status === 503, 'Database failure must not become 404');

$untouched = new Application(static fn () => throw new RuntimeException('Must not connect for invalid routes'), $config);
$invalidRoute = $untouched->handle('GET', '/pages', []);
check($invalidRoute->status === 404 && $invalidRoute->body['error']['code'] === 'route_not_found', 'Route error differs from article absence');
check($invalidRoute->body['error']['details']['database'] === 'not_checked', 'Route error must explicitly report no database query');
check(in_array('GET /v1/pages/{NOME_URL}', $invalidRoute->body['error']['details']['routes'], true), 'Show correct article route');
check($untouched->handle('GET', '/', [])->status === 200, 'Root identifies API, not missing article');
check($untouched->handle('GET', '/health', [])->body['database'] === 'not_checked', 'Health must not claim DB availability');

foreach ([['28000', 1045, 'database_access_denied'], ['42000', 1049, 'database_not_found'], ['42S02', 1146, 'database_table_not_found'], ['42S22', 1054, 'database_column_not_found'], ['HY000', 2002, 'database_connection_failed']] as [$state, $driver, $reason]) {
    $error = new PDOException('Original database error password=secretValue');
    $error->errorInfo = [$state, $driver, $error->getMessage()];
    $faulty = new Application(static fn () => throw $error, $config + ['debug' => true, 'db' => ['password' => 'secretValue']]);
    $result = $faulty->handle('GET', '/v1/pages/Caes', []);
    $details = $result->body['error']['details'];
    check($result->status === 503 && $details['layer'] === 'database', 'Classify database exception');
    check($details['stage'] === 'database_connection' && $details['reason'] === $reason, 'Database failure stage/reason');
    check($details['sqlstate'] === $state && $details['driver_code'] === $driver, 'Preserve database error codes');
    check(str_contains($details['message'], 'Original database error') && !str_contains($details['message'], 'secretValue'), 'Original message without password');
    $withoutDebug = new Application(static fn () => throw $error, $config);
    check(!isset($withoutDebug->handle('GET', '/v1/pages', [])->body['error']['details']['message']), 'Raw errors require server-side APP_DEBUG');
}

// Exercises actual queries and visibility filtering without a production database.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE PAGINAS (IDENT TEXT PRIMARY KEY, NOME_URL TEXT, NOME TEXT, CONTEUDO TEXT, CONTEUDO_TXT TEXT, DT_ATUALIZACAO TEXT, BUSCA TEXT, NIVEL INTEGER)');
    $db->exec('CREATE TABLE PAGINAS_CATEGORIAS (IDENT TEXT, NOME_URL TEXT, CATEGORIA TEXT, CATEGORIA_IDENT TEXT, CATEGORIA_URL TEXT, ORDEM INTEGER)');
    $db->exec('CREATE TABLE PAGINAS_LINKS (IDENT TEXT, NOME_URL TEXT, ID INTEGER, LINK TEXT, LINK_IDENT TEXT, LINK_URL TEXT, LINK_HASH TEXT, LINK_TXT TEXT)');
    $db->exec('CREATE TABLE PAGINAS_REDIRECIONAMENTOS (IDENT TEXT, ID INTEGER, PAGINA_URL TEXT)');
    $db->exec('CREATE TABLE PAGINAS_IMAGENS (IDENT TEXT, IMAGEM_IDENT TEXT)');
    $insert = $db->prepare('INSERT INTO PAGINAS VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
    foreach ([['a', 'Cães', 'S', 1], ['b', 'Restrito', 'S', 5], ['c', 'Oculto', 'N', 1], ['page@login', 'Login', 'S', 1], ['imagem@foto', 'Foto', 'S', 1]] as [$id, $slug, $search, $level]) {
        $insert->execute([$id, $slug, $slug, '<p>Conteúdo</p>', 'Conteúdo', $search, $level]);
    }
    $legacy = new LegacyPageRepository($db);
    check(count($legacy->list(100, '')) === 2, 'Only public articles and public media rows');
    check($legacy->find('Cães')['title'] === 'Cães', 'Lookup by NOME_URL');
    check($legacy->find("' OR 1=1 --") === null, 'Parameterized lookup');
    check($legacy->find('Restrito') === null, 'Private rows never reach static exports');
    $diagnostic = new Application(static fn () => $legacy, $config + ['debug' => true]);
    foreach ([['Restrito', 'level_above_public_max'], ['Oculto', 'not_searchable'], ['Login', 'special_page_not_exported'], ['a', 'identifier_is_not_url_slug']] as [$slug, $reason]) {
        $response = $diagnostic->handle('GET', '/v1/pages/' . rawurlencode($slug), []);
        check($response->status === 404 && $response->body['error']['code'] === 'not_found', 'Diagnostics do not bypass publication rules');
        check($response->body['error']['details']['database'] === 'query_succeeded', 'Distinguish successful query from database outage');
        check(in_array($reason, $response->body['error']['details']['lookup']['reasons'], true), 'Identify specific exclusion reason');
        check(!isset($response->body['data']), 'Do not expose article content through diagnostics');
    }
    $absent = $diagnostic->handle('GET', '/v1/pages/Ausente', []);
    check($absent->body['error']['details']['lookup']['record_found'] === false, 'Distinguish no row from filtered row');
    $private = new Application(static fn () => $legacy, $config);
    check(!isset($private->handle('GET', '/v1/pages/Restrito', [])->body['error']['details']['lookup']), 'Publication diagnostics disabled by default');
    // Real relation queries, including duplicate mentions and private sources.
    $insert->execute(['cat', 'Animais', 'Animais &amp; Natureza', '<p>Categoria</p>', '', 'S', 1]);
    $category = $db->prepare('INSERT INTO PAGINAS_CATEGORIAS VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['a', 'Cães'], ['b', 'Restrito'], ['c', 'Oculto']] as [$id, $slug]) $category->execute([$id, $slug, 'Animais &amp; Natureza', 'cat', 'Animais', 1]);
    $link = $db->prepare('INSERT INTO PAGINAS_LINKS VALUES (?, ?, ?, ?, ?, ?, NULL, ?)');
    foreach (['cat', 'cat', 'b', 'c'] as $index => $id) $link->execute([$id, 'Source', $index, 'Cães', 'a', 'Cães', 'Cães']);
    $link->execute(['a', 'Cães', 10, 'Novo', 'novo', 'Novo#Seção', 'Página nova']);
    for ($i = 1; $i <= 7; $i++) {
        $insert->execute(['r' . $i, 'Relacionado_' . $i, 'Relacionado ' . $i, '<p>Relacionado</p>', '', 'S', 1]);
        $category->execute(['r' . $i, 'Relacionado_' . $i, 'Animais', 'cat', 'Animais', 1]);
    }
    $data = $diagnostic->handle('GET', '/v1/pages/Cães', [])->body['data'];
    check($data['categories'][0]['title'] === 'Animais & Natureza', 'Category title is decoded and original URL retained');
    check(count($data['mentions']) === 1 && $data['mentions'][0]['slug'] === 'Animais', 'Deduplicate mentions and exclude restricted/hidden sources');
    $related = array_column($data['related_pages'], 'slug');
    $candidates = array_map(static fn (int $i): string => 'Relacionado_' . $i, range(1, 7));
    check(count($related) === 5 && count(array_unique($related)) === 5 && array_diff($related, $candidates) === [],
        'Five distinct related pages, without current/private/hidden articles');
    check($data['missing_links'][0]['slug'] === 'Novo#Seção', 'Preserve missing link anchor');
    $members = $legacy->find('Animais')['category_members'];
    check(count($members) === 8 && count(array_filter($members, static fn ($row) => in_array($row['slug'], ['Restrito', 'Oculto'], true))) === 0, 'Category members respect publication rules');
    $export = $legacy->list(100, '');
    $exported = array_values(array_filter($export, static fn ($row) => $row['id'] === 'a'))[0];
    check($exported['categories'] === $data['categories'] && $exported['mentions'] === $data['mentions'], 'Static export and individual lookup return the same categories and mentions');
    $exportedRelated = array_column($exported['related_pages'], 'slug');
    check(count($exportedRelated) === 5 && count(array_unique($exportedRelated)) === 5
        && array_diff($exportedRelated, $candidates) === [], 'Static export samples the same eligible set');
    $db->exec('DROP TABLE PAGINAS');
    $queryFailure = $diagnostic->handle('GET', '/v1/pages/Cães', []);
    check($queryFailure->status === 503 && $queryFailure->body['error']['details']['stage'] === 'page_lookup_query', 'Failed query identified separately from connection failure');
    echo "Repository queries passed.\n";
} else {
    echo "PDO SQLite unavailable: repository integration checks skipped (CI requires it).\n";
}
echo "API contract checks passed.\n";
require __DIR__ . '/study-material.inc.php';

