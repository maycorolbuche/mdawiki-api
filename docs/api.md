# CRUD de páginas — Postman

Base local: `http://localhost:8787`. Nesta etapa todas as rotas são públicas, sem token ou usuário autenticado; autoria fica nula. Não há deploy automático.

```sh
npm ci
npm run db:migrate:local
npm run check
npm run build
npm test
npm run dev
```

`npm test` executa um Worker e D1 isolados via Miniflare, aplica migrations e remove o banco de teste ao terminar. Não utiliza o banco do desenvolvimento nem o remoto.

| Método | Rota | Uso |
| --- | --- | --- |
| POST | /pages | Cria ou atualiza pelo slug gerado do título. |
| GET | /pages?limit=20&offset=0&type=specialty | Lista sem redirecionamentos; type é opcional. |
| GET | /pages/:slug | Consulta; slug antigo responde 301 com Location para a página atual. |
| PUT | /pages/:slug | Atualiza página existente; título diferente renomeia e cria redirecionamento. |
| DELETE | /pages/:slug | Exclui fisicamente página, relações, revisões, estatísticas e seus redirecionamentos. |

No Postman: Body → raw → JSON, com `Content-Type: application/json`. Não configure Authorization. Desative “Automatically follow redirects” para inspecionar o 301.

## Criar ou atualizar

`POST http://localhost:8787/pages`

```json
{
  "type": "specialty",
  "title": "Especialidade de Répteis",
  "content": "<h2>Requisitos</h2><ol><li>Conhecer as características dos répteis.</li><li>Identificar cinco espécies.</li></ol>",
  "categories": ["Especialidades", "Estudo da natureza"],
  "metadata": {
    "club_type": "pathfinders",
    "area": "EN",
    "code": "001",
    "level": 1,
    "introduction_year": 1986,
    "origin": "Instituição de origem",
    "is_obsolete": false
  },
  "status": "published",
  "change_summary": "Cadastro inicial"
}
```

Exemplo ilustrativo de metadados. Primeira gravação: 201; atualizações: 200. A resposta contém `data`, com ID, slug, campos da página e categorias com IDs. Slug: `especialidade-de-repteis`. O título é preservado exatamente. `type` é genérico e aceita letras minúsculas, números e underscores. `redirect` é reservado ao backend.

Categorias aceitam IDs existentes ou nomes; nomes são criados quando necessário, normalizados pelo slug e vinculados por ordem. Duplicados são removidos. Enviar `[]` limpa os vínculos. Não há CRUD separado de categorias nesta entrega.

`title` é obrigatório. Campos omitidos preservam valores existentes; em criação os padrões são `type=article`, `status=draft`, conteúdo vazio e metadata `{}`. `metadata` substitui o objeto inteiro, sem merge parcial. `categories` substitui toda a lista. `content`, `summary`, `cover_media_id` e `change_summary` aceitam null. `is_searchable` e `is_sponsored` aceitam booleanos. Campos desconhecidos, inclusive `slug` e campos de autoria, são rejeitados.

## Atualizar apenas o título equivalente

`POST http://localhost:8787/pages`

```json
{"title":"especíalidade-De REPTEIS"}
```

Atualiza o mesmo ID, pois o slug é igual; mantém o restante dos dados.

## Renomear

`POST http://localhost:8787/pages`

```json
{
  "title": "Especialidade de Répteis e Anfíbios",
  "old_slug": "especialidade-de-repteis",
  "change_summary": "Renomeação da página"
}
```

Preserva ID e histórico. O slug anterior fica em `pages` com `type=redirect` e `metadata.redirect` apontando para o ID original. Aliases anteriores apontam diretamente para o mesmo ID. Endereços ocupados, inclusive aliases da própria página, retornam 409; nunca são sobrescritos. Escrever usando um alias também retorna 409: use o slug atual. Sem `old_slug`, um título que gere outro slug cria uma nova página.

Alternativa: `PUT /pages/especialidade-de-repteis` com o título novo. Se enviar também `old_slug`, ele deve corresponder à rota.

## Conteúdo, mídias e histórico

HTML permitido: parágrafos, h2–h6, listas, tabelas, links, imagens, figuras e formatação básica. Scripts, embeds, eventos, classes e estilos livres são removidos. URLs aceitas: HTTP(S), caminhos locais; links também aceitam âncoras e mailto. Texto de busca é derivado do HTML sanitizado.

Mídias existentes são referenciadas por `cover_media_id`, chaves de metadata terminadas em `_media_id`, ou `<img src="/caminho/arquivo.jpg" data-media-id="42" alt="Descrição">`. IDs são validados e sincronizados em `page_media`. Upload ainda não está implementado. URLs de imagem sem ID não geram vínculo de mídia.

Cada gravação cria uma revisão em `page_revisions` com conteúdo sanitizado, metadata e categorias. As gravações relacionadas usam batch transacional do D1. Concorrência de versão ou slug retorna 409 para recarregar e tentar novamente. A migration 0002 protege destinos e remove aliases ao excluir páginas. Mapeamentos legados podem impedir a exclusão física e retornar 409.

Erros JSON: `{"error":"mensagem"}`. Status: 400 JSON/URL inválido; 404 ausente; 405 método; 409 conflito; 413 payload maior que o limite; 415 Content-Type; 422 validação. Limite: 1 milhão de caracteres JSON; paginação até 100; até 100 categorias. Não há autenticação, autorização, importação do legado, busca textual ou rotas de histórico nesta etapa.
