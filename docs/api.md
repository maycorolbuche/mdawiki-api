# Contrato público v1

Uma página retorna `{ "data": { "id", "slug", "title", "html", "description", "updated_at", "canonical_url" } }`.

A listagem retorna `{ "data": [páginas], "next_cursor": "..." }`. Envie o cursor como `after` até receber `null`; `limit` aceita inteiros de 1 a 500. IDs são opacos. Não os derive do título. Slugs preservam o formato `NOME_URL` legado e devem ser enviados com `encodeURIComponent`.

Erros: `{ "error": { "code": "...", "message": "...", "request_id": "..." } }`. O identificador aparece em falhas internas; detalhes ficam nos logs. Artigo não publicado ou inexistente: 404. Falha de conexão/configuração: 503, jamais 404. Métodos de escrita: 405 nesta base.

Resposta de leitura não depende de sessão. CORS não é autorização: apenas controla a leitura pelo navegador. Não há cookies/credenciais nesse contrato público. Autenticação será um contrato próprio em etapa posterior.

O frontend gera HTML e metatags em build, mantém o snapshot até a API responder e atualiza o conteúdo após montagem. Se a API responder 404, o snapshot é retirado. Se responder 503 ou falhar, mantém-se o snapshot com aviso e retry. Uma URL nova servida pelo `404.html` só recebe status HTTP 200 depois da próxima geração estática.
