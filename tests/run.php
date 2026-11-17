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

// Exercises actual queries and visibility filtering without a production database.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE PAGINAS (IDENT TEXT PRIMARY KEY, NOME_URL TEXT, NOME TEXT, CONTEUDO TEXT, CONTEUDO_TXT TEXT, DT_ATUALIZACAO TEXT, BUSCA TEXT, NIVEL INTEGER)');
    $insert = $db->prepare('INSERT INTO PAGINAS VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
    foreach ([['a', 'Cães', 'S', 1], ['b', 'Restrito', 'S', 5], ['c', 'Oculto', 'N', 1], ['page@login', 'Login', 'S', 1], ['imagem@foto', 'Foto', 'S', 1]] as [$id, $slug, $search, $level]) {
        $insert->execute([$id, $slug, $slug, '<p>Conteúdo</p>', 'Conteúdo', $search, $level]);
    }
    $legacy = new LegacyPageRepository($db);
    check(count($legacy->list(100, '')) === 1, 'Only publicly exportable article rows');
    check($legacy->find('Cães')['title'] === 'Cães', 'Lookup by NOME_URL');
    check($legacy->find("' OR 1=1 --") === null, 'Parameterized lookup');
    check($legacy->find('Restrito') === null, 'Private rows never reach static exports');
    echo "Repository queries passed.\n";
} else {
    echo "PDO SQLite unavailable: repository integration checks skipped (CI requires it).\n";
}
echo "API contract checks passed.\n";
