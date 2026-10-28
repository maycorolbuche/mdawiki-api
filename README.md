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

Depois da configuração, aplique explicitamente a migration remota e publique:

```sh
npm run db:migrate:remote
npm run deploy
```

O deploy não aplica migrations automaticamente. Os comandos remotos exigem acesso à conta Cloudflare. Nenhum recurso remoto foi criado ou publicado nesta entrega.

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
