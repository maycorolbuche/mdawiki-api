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

| Método | Rota                                    | Uso                                                                                   |
| ------ | --------------------------------------- | ------------------------------------------------------------------------------------- |
| POST   | /pages                                  | Cria pelo slug do título; endereço ocupado retorna 409.                               |
| GET    | /page-schema                            | Diretrizes de HTML, limites, tipos e metadados. Suporta ETag/304.                     |
| GET    | /pages?limit=20&offset=0&type=specialty | Lista sem redirecionamentos; type é opcional.                                         |
| GET    | /pages/:slug                            | Consulta; slug antigo responde 301 com Location para a página atual.                  |
| PUT    | /pages/:slug                            | Atualiza página existente; título diferente renomeia e cria redirecionamento.         |
| DELETE | /pages/:slug                            | Exclui fisicamente página, relações, revisões, estatísticas e seus redirecionamentos. |

No Postman: Body → raw → JSON, com `Content-Type: application/json`. Não configure Authorization. Desative “Automatically follow redirects” para inspecionar o 301.

## Criar

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

Criação: 201; atualizações por PUT: 200. A resposta contém `data`, com ID, slug, campos da página e categorias com IDs. Slug: `especialidade-de-repteis`. O título é preservado como recebido. `title`, após trim, e o slug gerado precisam ter entre 2 e 250 caracteres. Slugs de um caractere são reservados ao sistema. `type` aceita somente os tipos de `GET /page-schema`; `redirect` é reservado ao backend.

Categorias aceitam IDs existentes ou nomes; nomes são criados quando necessário, normalizados pelo slug e vinculados por ordem. Duplicados são removidos. Enviar `[]` limpa os vínculos. Não há CRUD separado de categorias nesta entrega.

`title` é obrigatório e deve gerar um slug com pelo menos dois caracteres. Campos omitidos preservam valores existentes; em criação os padrões são `type=article`, `status=draft`, conteúdo vazio e metadata `{}`. `metadata` substitui o objeto inteiro, sem merge parcial. `categories` substitui toda a lista. `content`, `summary`, `cover_media_id` e `change_summary` aceitam null. `is_searchable` e `is_sponsored` aceitam booleanos. Campos desconhecidos, inclusive `slug` e campos de autoria, são rejeitados.

## Editar

Use `PUT /pages/:slug` com o título e os campos alterados. POST sem `old_slug` agora é criação estrita: responde 409 se o slug já estiver ocupado, sem modificar a página ou seu histórico.

## Renomear

`POST http://localhost:8787/pages`

```json
{
  "title": "Especialidade de Répteis e Anfíbios",
  "old_slug": "especialidade-de-repteis",
  "change_summary": "Renomeação da página"
}
```

Preserva ID e histórico. O slug anterior fica em `pages` com `type=redirect` e `metadata.redirect` apontando para o ID original. Aliases anteriores apontam diretamente para o mesmo ID. Endereços ocupados, inclusive aliases da própria página, retornam 409; nunca são sobrescritos. Escrever usando um alias também retorna 409: use o slug atual. Por compatibilidade, POST com `old_slug` continua permitindo renomeação explícita. O frontend usa PUT para todas as edições.

Alternativa: `PUT /pages/especialidade-de-repteis` com o título novo. Se enviar também `old_slug`, ele deve corresponder à rota.

## Conteúdo, mídias e histórico

HTML permitido: parágrafos, h2–h6, listas, tabelas, links, imagens, figuras e formatação básica. Scripts, embeds, eventos, classes e estilos livres são removidos. URLs aceitas: HTTP(S), caminhos locais; links também aceitam âncoras e mailto. Texto de busca é derivado do HTML sanitizado.

Mídias existentes são referenciadas por `cover_media_id`, chaves de metadata terminadas em `_media_id`, ou `<img src="/caminho/arquivo.jpg" data-media-id="42" alt="Descrição">`. IDs são validados e sincronizados em `page_media`. Upload ainda não está implementado. URLs de imagem sem ID não geram vínculo de mídia.

Cada gravação cria uma revisão em `page_revisions` com conteúdo sanitizado, metadata e categorias. As gravações relacionadas usam batch transacional do D1. Concorrência de versão ou slug retorna 409 para recarregar e tentar novamente. A migration 0002 protege destinos e remove aliases ao excluir páginas. Mapeamentos legados podem impedir a exclusão física e retornar 409.

Erros JSON: `{"error":"mensagem"}`. Status: 400 JSON/URL inválido; 404 ausente; 405 método; 409 conflito; 413 payload maior que o limite; 415 Content-Type; 422 validação. Limite: 1 milhão de caracteres JSON; paginação até 100; até 100 categorias. Não há autenticação, autorização, importação do legado, busca textual ou rotas de histórico nesta etapa.

## Diretrizes centralizadas

`GET /page-schema` retorna `{data:{version,page,html,types}}`. `version` é o SHA-256 das definições públicas; qualquer mudança de configuração altera o valor. A resposta tem ETag e Cache-Control: no-cache. Envie If-None-Match para revalidar e receber 304 quando não houver mudança.

- `src/config/content.ts`: tags, atributos por tag, protocolos e regras de URLs. H1 fica fora do corpo; H2–H6 estão habilitados. A mesma definição é utilizada na sanitização do servidor.
- `src/config/page.ts`: limites, tipo padrão e tipos editoriais, com campos de metadata, rótulos, controles, opções e limites. Adicionar um tipo exige registrá-lo aqui; não há migração de banco para esse catálogo.
- `src/config/http.ts`: origens padrão de CORS. A variável opcional `CORS_ORIGINS`, com origens separadas por vírgula, substitui os padrões (configurar em `.dev.vars` local ou nas variáveis do Worker). Usar origens exatas, incluindo esquema e porta. Padrões: localhost:5006, 127.0.0.1:5006, https://mda.wiki.br e https://www.mda.wiki.br.

O frontend obtém as diretrizes junto da primeira leitura, reutiliza na sessão e revalida ao abrir e salvar o editor. O servidor valida independentemente do cliente. Alterações nos arquivos TypeScript precisam de novo build/deploy.

Tipos iniciais: article, specialty, person e club. Metadados desconhecidos são rejeitados, inclusive após trocar o tipo. Metadata substitui o objeto inteiro: envie todos os campos que deseja manter, e `{}` para limpar. Campos são opcionais salvo `required: true`; null limpa um campo opcional. Números são inteiros, booleanos precisam ser booleanos e datas usam YYYY-MM-DD com calendário válido. Falecimento não pode anteceder nascimento. IDs de mídia precisam existir. Campos e opções definitivos devem ser mantidos no catálogo, sem duplicar as regras nos formulários.

Esta alteração não aplica migrations remotas nem altera dados existentes. Registros antigos com tipos/metadados fora do catálogo permanecem legíveis; configure seus campos antes de editá-los.
