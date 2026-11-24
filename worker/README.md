# API MDAWiki no Cloudflare

Worker de homologação na branch `dev` do repositório da API. Esta etapa cobre a leitura pública e usa o HTML armazenado no banco. O Worker ainda carece de paridade com o PHP para autenticação, edição, registro de visitas, e-mails e tarefas agendadas. Use um domínio de homologação.

## Organização

| Arquivo | Responsabilidade |
| --- | --- |
| `src/index.ts` | Roteamento Hono, validação HTTP, CORS e erros no formato da API. |
| `src/pages.ts` | Exportação paginada, relações, busca, home e apresentação de artigos. |
| `src/directory.ts` | Perfis públicos e histórico público de imagens. |
| `src/media.ts` | Autoriza a chave no D1 antes de ler ou entregar um objeto do R2 privado. |
| `migrations/0001_schema.sql` | Schema D1 das tabelas normalizadas. |
| `scripts/export-mysql.php` | Exportação consistente do MySQL, validada contra SQLite antes da importação no D1. |

## Desenvolvimento local

Com Node.js 24, execute dentro de `worker/`.

```sh
npm ci
npm run check
npm test
npx wrangler d1 migrations apply mdawiki-dev --local
npm run dev
```

O banco local começa vazio. Use registros fictícios para desenvolvimento. A configuração usa o nome `mdawiki-api-dev` e um identificador D1 de exemplo que precisa ser substituído pelo ID do banco de homologação antes de um deploy remoto.

## Homologação no Cloudflare

1. Crie o banco D1 `mdawiki-dev` e o bucket privado `mdawiki-media-dev`. Preencha o ID do D1 em `wrangler.jsonc` e ajuste `ALLOWED_ORIGINS` à origem do front de teste.
2. Aplique as migrations com `npx wrangler d1 migrations apply mdawiki-dev --remote` a partir de `worker/`.
3. Conecte um Worker de teste ao repositório com branch de deploy `dev`, diretório raiz `worker` e comando de deploy `npm run deploy`. Para deploy manual, use o mesmo comando em `worker/` depois de autenticar o Wrangler.
4. Copie os objetos de teste para o bucket nas chaves existentes, como `articles/<nome>` e `users/<nome>`. Verifique `GET /health`, `GET /v1/statistics` e `GET /media/...` no endereço do Worker de homologação.

## Importação

Use PHP CLI com PDO MySQL e SQLite. Rode a exportação de uma cópia consistente do MySQL com schema normalizado. Use credenciais de leitura e um caminho fora do repositório. O SQL inclui dados de contas e hashes de senha. Em homologação compartilhe apenas dados fictícios ou previamente sanitizados.

```sh
export MYSQL_DSN='mysql:host=127.0.0.1;dbname=mdawiki;charset=utf8mb4'
export MYSQL_USER='usuario_de_leitura'
# Carregue MYSQL_PASSWORD no ambiente por um meio seguro.
php scripts/export-mysql.php /caminho/privado/mdawiki-data.sql
npx wrangler d1 execute mdawiki-dev --local --file=/caminho/privado/mdawiki-data.sql
```

O exportador preserva IDs e vínculos, valida as restrições no SQLite e imprime as contagens e o tamanho com índices. Ele recusa sobrescrever o arquivo de saída ou gerar instruções acima do limite do D1. Use uma base de destino nova para cada importação. Compare as contagens das tabelas e teste leitura e mídia antes de considerar uma importação remota. O SQL gerado contém somente dados. A estrutura vem das migrations.

## Diferenças nesta etapa

- A busca usa a comparação nativa do SQLite. A equivalência completa de acentos e maiúsculas com o MySQL ainda precisa de validação.
- A leitura usa HTML importado, com substituição dos marcadores de URL e do domínio antigo da API nas referências de mídia. A decoração dinâmica de links de artigos e a integração com material de estudo ainda precisam de portabilidade.
- A entrega R2 cobre as chaves normalizadas de artigos públicos e fotos associadas a contas ativas. O sistema antigo continua responsável pelos fluxos de edição e compatibilidade com outras chaves de mídia.
- A autenticação, a edição, a restauração, os uploads, a gravação de visitas e o processamento agendado são etapas seguintes da migração.
