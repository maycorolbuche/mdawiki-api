# MDAWiki API

Estrutura inicial em TypeScript para Cloudflare Workers e D1. Ainda não há rotas de negócio, CRUD, autenticação, upload ou importação do legado. O Worker responde 404 a todas as requisições.

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
