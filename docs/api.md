# Contrato público v1

Uma página retorna `{ "data": { "id", "slug", "title", "html", "description", "updated_at", "canonical_url" } }`.

Também retorna cinco arrays de links `{ "id": "...", "slug": "...", "title": "..." }`: `categories`, `mentions`, `related_pages`, `category_members` e `missing_links`. Estão presentes tanto na consulta individual quanto na exportação, inclusive quando vazios. Categorias preservam a ordem do legado; menções são deduplicadas; relacionados compartilham categorias, excluem o próprio artigo e são limitados aos primeiros cinco em ordem alfabética estável. Membros de categoria e destinos existentes respeitam os filtros públicos. Links inexistentes são os destinos ainda sem registro, não os artigos privados. `missing_links.slug` pode conter um fragmento `#`; os demais slugs são nomes de páginas.

A listagem retorna `{ "data": [páginas], "next_cursor": "..." }`. Envie o cursor como `after` até receber `null`; `limit` aceita inteiros de 1 a 500. IDs são opacos. Não os derive do título. Slugs preservam o formato `NOME_URL` legado e devem ser enviados com `encodeURIComponent`.

Erros: `{ "error": { "code": "...", "message": "...", "request_id": "..." } }`. O identificador aparece em falhas internas; detalhes ficam nos logs. Artigo não publicado ou inexistente: 404. Falha de conexão/configuração: 503, jamais 404. Métodos de escrita: 405 nesta base.

## Diagnóstico de erros

- `GET /` identifica o serviço e lista as rotas; não consulta o banco.
- Rota incorreta (por exemplo `/pages`): HTTP 404, `code=route_not_found`, caminho recebido, rotas esperadas e `details.database=not_checked`.
- Artigo não localizado em `/v1/pages/{NOME_URL}`: HTTP 404, `code=not_found`, `details.layer=article`, `details.database=query_succeeded`. Isso não indica indisponibilidade do banco; o registro pode estar ausente ou excluído pelos filtros públicos.
- Falha interna: HTTP 503, `request_id` para correlação com os logs, `details.layer` (`database`, `configuration` ou `application`) e `details.stage` (`database_connection`, `page_export_query`, `page_lookup_query` ou `page_diagnostic_query`). Erros PDO incluem SQLSTATE, código do driver e classificação como `database_access_denied`, `database_not_found`, `database_table_not_found`, `database_column_not_found`, `database_connection_failed` ou `database_driver_missing`.

Para investigar temporariamente, configure **`APP_DEBUG=true` no `.env` da API**. As respostas 503 passam a incluir `details.type` e `details.message` com a mensagem original da exceção (senha configurada e valores de password mascarados; não enviamos DSN, configuração completa ou stack trace).

Com depuração ativa, o 404 de artigo inclui `details.slug` e `details.lookup`: identifica registro inexistente, busca por IDENT em vez de NOME_URL, `BUSCA` diferente de S, nível acima de `PUBLIC_MAX_LEVEL`, página especial `page@` ou mídia `imagem@`. A consulta diagnóstica não retorna conteúdo, não libera registros restritos e não altera os filtros de exportação. Desative APP_DEBUG após a investigação; esse detalhe pode revelar a existência de um registro não público. O parâmetro de URL `debug=true` não ativa esse modo.

Resposta de leitura não depende de sessão. CORS não é autorização: apenas controla a leitura pelo navegador. Não há cookies/credenciais nesse contrato público. Autenticação será um contrato próprio em etapa posterior.

O frontend gera HTML e metatags em build, mantém o snapshot até a API responder e atualiza o conteúdo após montagem. Se a API responder 404, o snapshot é retirado. Se responder 503 ou falhar, mantém-se o snapshot com aviso e retry. Uma URL nova servida pelo `404.html` só recebe status HTTP 200 depois da próxima geração estática.
