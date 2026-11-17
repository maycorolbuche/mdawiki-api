<?php
declare(strict_types=1);

namespace MDAWiki;

use Closure;
use Throwable;

final class Application
{
    /** @param Closure(): PageRepository $repository */
    public function __construct(private readonly Closure $repository, private readonly array $config)
    {
    }

    public function handle(string $method, string $path, array $query, string $origin = ''): Response
    {
        $headers = $origin !== '' ? ['Vary' => 'Origin'] : [];
        if ($origin !== '') {
            if (!in_array($origin, $this->config['origins'], true)) {
                return new Response(403, ['error' => ['code' => 'origin_not_allowed', 'message' => 'Origem não autorizada.']], $headers);
            }
            $headers['Access-Control-Allow-Origin'] = $origin;
        }
        if ($method === 'OPTIONS') {
            return new Response(204, [], $headers + ['Access-Control-Allow-Methods' => 'GET, OPTIONS', 'Access-Control-Allow-Headers' => 'Accept, Content-Type']);
        }
        if ($method !== 'GET') {
            return new Response(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Método não permitido.']], $headers + ['Allow' => 'GET, OPTIONS']);
        }
        if ($path === '/health') {
            return new Response(200, ['status' => 'ok', 'service' => 'mdawiki-api', 'version' => '1', 'database' => 'not_checked'], $headers);
        }
        if ($path === '/') {
            return new Response(200, ['service' => 'mdawiki-api', 'version' => '1', 'database' => 'not_checked', 'routes' => $this->routes()], $headers);
        }
        $stage = 'routing';
        $debug = (bool) ($this->config['debug'] ?? false);
        try {
            if ($path === '/v1/pages') {
                $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
                if ($limit === false || !is_string($query['after'] ?? '')) {
                    return new Response(400, ['error' => ['code' => 'invalid_pagination', 'message' => 'Paginação inválida.']], $headers);
                }
                $after = $query['after'] ?? '';
                if (mb_strlen($after) > 250) {
                    return new Response(400, ['error' => ['code' => 'invalid_pagination', 'message' => 'Cursor inválido.']], $headers);
                }
                $stage = 'database_connection';
                $repository = ($this->repository)();
                $stage = 'page_export_query';
                $rows = $repository->list($limit + 1, $after);
                $hasMore = count($rows) > $limit;
                $rows = array_slice($rows, 0, $limit);
                return new Response(200, ['data' => array_map($this->present(...), $rows), 'next_cursor' => $hasMore ? end($rows)['id'] : null], $headers);
            }
            if (str_starts_with($path, '/v1/pages/')) {
                $slug = rawurldecode(substr($path, strlen('/v1/pages/')));
                if ($slug === '' || mb_strlen($slug) > 250 || strpbrk($slug, "/\\\0") !== false || preg_match('/[\x00-\x1f\x7f]/', $slug)) {
                    return new Response(400, ['error' => ['code' => 'invalid_slug', 'message' => 'Endereço inválido.']], $headers);
                }
                $stage = 'database_connection';
                $repository = ($this->repository)();
                $stage = 'page_lookup_query';
                $row = $repository->find($slug);
                if ($row !== null) {
                    return new Response(200, ['data' => $this->present($row)], $headers);
                }
                $details = ['layer' => 'article', 'stage' => $stage, 'database' => 'query_succeeded', 'lookup_field' => 'NOME_URL', 'reason' => 'not_found_or_not_public'];
                if ($debug) {
                    $details['slug'] = $slug;
                    if ($repository instanceof DiagnosablePageRepository) {
                        $stage = 'page_diagnostic_query';
                        $details['lookup'] = $repository->diagnose($slug);
                    }
                }
                return new Response(404, ['error' => ['code' => 'not_found', 'message' => 'Artigo não encontrado ou não disponível para consulta pública.', 'details' => $details]], $headers);
            }
            return new Response(404, ['error' => ['code' => 'route_not_found', 'message' => 'Rota da API não encontrada. O banco de dados não foi consultado.', 'details' => ['layer' => 'routing', 'method' => $method, 'path' => $path, 'database' => 'not_checked', 'routes' => $this->routes()]]], $headers);
        } catch (Throwable $error) {
            $id = bin2hex(random_bytes(8));
            error_log('[' . $id . '] ' . $error);
            $details = ErrorDetails::describe($error, $stage, $debug, $this->config);
            return new Response(503, ['error' => ['code' => 'service_unavailable', 'message' => $details['layer'] === 'database' ? 'Falha no acesso ao banco de dados.' : 'Falha ao processar a consulta.', 'request_id' => $id, 'details' => $details]], $headers);
        }
    }

    private function routes(): array
    {
        return ['GET /health', 'GET /v1/pages', 'GET /v1/pages/{NOME_URL}'];
    }

    private function present(array $row): array
    {
        $slug = $row['slug'];
        $html = str_replace(['{{{url_site}}}', '{{{url_diretorio}}}'], [$this->config['frontend_url'] . '/', $this->config['media_url'] . '/'], $row['html'] ?? '');
        return [
            'id' => $row['id'], 'slug' => $slug, 'title' => html_entity_decode($row['title'] ?? $slug, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'html' => $html,
            'description' => mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($row['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 240),
            'updated_at' => $row['updated_at'] ?? null,
            'canonical_url' => $this->config['frontend_url'] . '/' . rawurlencode($slug) . '/',
        ];
    }
}
