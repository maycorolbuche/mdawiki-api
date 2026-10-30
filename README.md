# MDAWiki API

API em TypeScript para Cloudflare Workers e D1, com CRUD público de páginas, categorias vinculadas, histórico e redirecionamentos. Nesta etapa não há autenticação, upload ou importação do legado. Consulte [rotas e exemplos para Postman](docs/api.md).

## Desenvolvimento

Requer Node.js 22 ou superior e npm.

```sh
npm ci
npm run db:migrate:local
npm run dev
```

O banco local é persistido em `.wrangler/`, que não deve ser versionado.

## Validação

```sh
npm run check
npm run build
npm test
```

`check` gera os tipos do ambiente a partir da configuração Wrangler e executa o TypeScript. `build` empacota o Worker sem publicar.

## Configuração na Cloudflare

```sh
npx wrangler login
npx wrangler d1 create mdawiki
```

Substitua o `database_id` de exemplo em `wrangler.jsonc` pelo UUID retornado. O identificador composto apenas por zeros é um placeholder para desenvolvimento local; não é um banco remoto existente.

Depois da configuração, publique com:

```sh
npm run deploy
```

Esse comando aplica primeiro as migrations pendentes no D1 remoto e publica o Worker somente se todas terminarem com sucesso. O histórico de migrations do D1 impede reaplicar arquivos já executados. Migrations já aplicadas não devem ser editadas.

### Deploy automático por commit no Cloudflare Workers Builds

No Worker da API, abrir Settings → Builds → Build configuration e configurar:

| Campo | Valor |
| --- | --- |
| Branch de produção | main |
| Build command | npm run check && npm test && npm run build |
| Deploy command | npm run deploy |

A integração Git do Cloudflare executará validações, migrations remotas e publicação a cada push/merge na branch de produção. Usar `npx wrangler deploy` diretamente ignora a etapa de migrations. O token usado pelo build precisa permitir a edição do D1, além do deploy do Worker.

Não executar esse deploy em builds de preview apontados ao banco de produção. O binding DB de wrangler.jsonc identifica o banco remoto que será atualizado. Para ambientes separados, configurar banco e comando próprios.

`npm run build` continua sendo somente empacotamento dry-run, sem migrations remotas. A CI do GitHub valida tipos, testes isolados e esse build; ela não migra o banco de produção.

Uma falha de migration interrompe o comando antes do deploy, mas migrations anteriores que já terminaram podem ter sido aplicadas. Também não há rollback automático do banco se o deploy posterior falhar. Usar migrations incrementais compatíveis com o Worker ainda publicado.

Os comandos remotos exigem acesso à conta Cloudflare. Nenhuma migration remota nem publicação foi executada durante a preparação desta alteração.

## Banco

Consulte [docs/database.md](docs/database.md). A migration inicial cria somente estruturas, sem registros de exemplo ou dados legados. Não edite migrations já aplicadas: crie arquivos SQL numerados novos em `migrations/`.

Referências oficiais: [D1 migrations](https://developers.cloudflare.com/d1/reference/migrations/), [Wrangler configuration](https://developers.cloudflare.com/workers/wrangler/configuration/) e [TypeScript](https://developers.cloudflare.com/workers/languages/typescript/).

## Organização do código

- `src/db/schema.ts`: mapeamento Drizzle das tabelas D1 existentes.
- `src/db/index.ts`: conexão com D1 e execução atômica de lotes.
- `src/models/`: acesso tipado às entidades.
- `src/services/PageService.ts`: regras de negócio e gravações.
- `src/controllers/PageController.ts`: validação HTTP e respostas JSON.
- `src/routes/pages.ts`: definição das rotas Hono.
- `src/validators/page.ts`: validação do payload de páginas.

O esquema Drizzle reflete a migration SQL existente; **não** execute geração automática de migrations sobre um banco existente sem revisar o diff. O lockfile precisa ser atualizado com `npm install` antes de usar `npm ci`.

## Integração com o frontend

`GET /page-schema` publica limites, HTML permitido e o catálogo de tipos/metadados. As configurações estão em `src/config/` e alimentam também a validação do servidor. Consulte `docs/api.md` para CORS, ETag e o contrato de gravação. POST sem old_slug cria; PUT /pages/:slug edita. Título e slug gerado exigem pelo menos dois caracteres. Não há autenticação nesta etapa.
