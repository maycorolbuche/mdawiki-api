# Contrato público v1

## Operações independentes

A API mantém as rotas públicas abaixo e adiciona os fluxos do aplicativo. Nenhuma operação depende de `mdawiki-site` em execução. Instalação, acervo, SMTP, permissões e recuperação de escritas estão em [deployment.md](deployment.md).

`GET /v1/auth/session` retorna `{data:{user,csrf_token}}`, onde user é null antes do login. O navegador envia `credentials: include`; toda escrita envia `Content-Type: application/json`, `Origin` autorizado e `X-CSRF-Token`. CORS permite somente as origens configuradas e credenciais explícitas. Login/logout/troca de senha rotacionam o token CSRF. Senhas, hashes, códigos e dados de sessão não são retornados no perfil.

| Rota | Operação |
|---|---|
| POST /v1/auth/login | email, password, remember (booleano opcional) |
| POST /v1/auth/logout | Encerrar sessão |
| POST /v1/auth/register | name, email, password, password_confirmation |
| POST /v1/auth/activate | code |
| POST /v1/auth/resend ou /forgot | email; resposta genérica para evitar enumeração |
| POST /v1/auth/reset | email, token, password, password_confirmation |
| GET ou PUT /v1/profile | Perfil próprio; escrita aceita somente campos cadastrais permitidos |
| POST /v1/profile/password | current_password, password, password_confirmation |
| POST /v1/profile/email | email; depois email + code para confirmar |
| POST /v1/profile/photo | data: imagem em data URL (SVG sanitizado) |
| GET /v1/pages/{slug}/editor | title, source, format, exists, media, base_version; requer login/permissão |
| PUT /v1/pages/{slug} | title, source, base_version; cria ou salva revisão, HTTP 409 em conflito |
| POST .../preview | source; processa sem persistir |
| GET .../history?before=N | Até 50 revisões, next_cursor e base_version |
| POST .../restore | revision e base_version; cria nova revisão |
| POST .../rebuild | Reprocessar cache/índices; administrador |
| GET /v1/view/{slug} | Artigo autenticado com verificação de nível; is_public=false exige noindex |
| GET /v1/search?q=texto | items, next_offset; limit até 100 e offset |
| GET /v1/catalog?type=categorias | Listas públicas; mesmos parâmetros de paginação |
| GET /v1/statistics ou /home | Contadores, alterações recentes e populares |
| GET /v1/users ou /users/{id} | Administrador nível 5; campos cadastrais, sem credenciais |
| POST /v1/media | data: imagem em data URL; retorna url, filename, width, height |
| POST /v1/visits | slug; contador diário público, com debounce e sem contagem no build |

Tipos de catálogo: paginas, categorias, imagens, videos, documentacao/codigos, listas, links_inexistentes, paginas_sem_categoria, redirecionamentos, imagens_sem_pagina, imagens_inexistentes. `atualizar` exige administrador. Diretório de usuários aceita filtros NOME, APELIDO, EMAIL, CIDADE, ESTADO, CLUBE, ASSOCIACAO, TIPO_CLUBE, ATIVO e HAS_PHOTO (S/N).

Artigos incluem também redirect_slug (ou null); páginas de imagem incluem image_usage com os artigos públicos que as utilizam. O frontend segue redirecionamentos internamente, com limite para ciclos. Somente artigos públicos entram na exportação; fontes, histórico, contas e leitura privada nunca entram no snapshot público.

Uma página retorna `{ "data": { "id", "slug", "title", "html", "description", "updated_at", "canonical_url" } }`.

Também retorna cinco arrays de links `{ "id": "...", "slug": "...", "title": "..." }`: `categories`, `mentions`, `related_pages`, `category_members` e `missing_links`. Estão presentes tanto na consulta individual quanto na exportação, inclusive quando vazios. Categorias preservam a ordem do legado; menções são deduplicadas; relacionados compartilham categorias, excluem o próprio artigo e são limitados aos primeiros cinco em ordem alfabética estável. Membros de categoria e destinos existentes respeitam os filtros públicos. Links inexistentes são os destinos ainda sem registro, não os artigos privados. `missing_links.slug` pode conter um fragmento `#`; os demais slugs são nomes de páginas.

A listagem retorna `{ "data": [páginas], "next_cursor": "..." }`. Envie o cursor como `after` até receber `null`; `limit` aceita inteiros de 1 a 500. IDs são opacos. Não os derive do título. Slugs preservam o formato `NOME_URL` legado e devem ser enviados com `encodeURIComponent`.

Erros: `{ "error": { "code": "...", "message": "...", "request_id": "..." } }`. O identificador aparece em falhas internas; detalhes ficam nos logs. Artigo não publicado ou inexistente: 404. Falha de conexão/configuração: 503, jamais 404. Operações autenticadas distinguem 401, 403, 409 (conflito), 422 (validação) e 429 (limite de tentativas).

## Diagnóstico de erros

- `GET /` identifica o serviço e lista as rotas; não consulta o banco.
- Rota incorreta (por exemplo `/pages`): HTTP 404, `code=route_not_found`, caminho recebido, rotas esperadas e `details.database=not_checked`.
- Artigo não localizado em `/v1/pages/{NOME_URL}`: HTTP 404, `code=not_found`, `details.layer=article`, `details.database=query_succeeded`. Isso não indica indisponibilidade do banco; o registro pode estar ausente ou excluído pelos filtros públicos.
- Falha interna: HTTP 503, `request_id` para correlação com os logs, `details.layer` (`database`, `configuration` ou `application`) e `details.stage` (`database_connection`, `page_export_query`, `page_lookup_query` ou `page_diagnostic_query`). Erros PDO incluem SQLSTATE, código do driver e classificação como `database_access_denied`, `database_not_found`, `database_table_not_found`, `database_column_not_found`, `database_connection_failed` ou `database_driver_missing`.

Para investigar temporariamente, configure **`APP_DEBUG=true` no `.env` da API**. As respostas 503 passam a incluir `details.type` e `details.message` com a mensagem original da exceção (senha configurada e valores de password mascarados; não enviamos DSN, configuração completa ou stack trace).

Com depuração ativa, o 404 de artigo inclui `details.slug` e `details.lookup`: identifica registro inexistente, busca por IDENT em vez de NOME_URL, `BUSCA` diferente de S, nível acima de `PUBLIC_MAX_LEVEL`, página especial `page@`. A consulta diagnóstica não retorna conteúdo, não libera registros restritos e não altera os filtros de exportação. Desative APP_DEBUG após a investigação; esse detalhe pode revelar a existência de um registro não público. O parâmetro de URL `debug=true` não ativa esse modo.

As rotas públicas de exportação não dependem de sessão. CORS não substitui autorização: rotas de conta, fonte, histórico e administração verificam sessão e permissão no servidor, além de CSRF nas escritas.

O frontend gera HTML e metatags em build, mantém o snapshot até a API responder e atualiza o conteúdo após montagem. Se a API responder 404, o snapshot é retirado. Se responder 503 ou falhar, mantém-se o snapshot com aviso e retry. Uma URL nova servida pelo `404.html` só recebe status HTTP 200 depois da próxima geração estática.

No editor normalizado, `GET /v1/pages/{slug}/editor` devolve o texto sem a marcação de categorias, a lista atual em `categories` e as sugestões em `category_options`. Envie `categories` como uma lista ordenada de slugs ou nomes em `PUT /v1/pages/{slug}`. Um nome novo cria uma página de categoria vazia. Uma lista vazia remove todos os vínculos. A omissão do campo mantém a compatibilidade com textos legados.
