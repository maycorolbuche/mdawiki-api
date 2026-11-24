# API MDAWiki no Cloudflare

Worker de homologação na branch `dev` do repositório da API. Esta etapa cobre leitura pública, sessão, login, logout, consulta do próprio perfil, contagem de visitas, histórico de artigos e escrita de imagens. Os artigos usam o HTML armazenado no banco. A edição e o reprocessamento de artigos estão disponíveis para validação da marcação compatível. Edição de contas, fluxos de e-mail e a renderização de macros restantes ainda dependem do PHP. Use um domínio de homologação. O Worker fica na raiz do repositório. A [API PHP](legacy/README.md) fica em `legacy/` durante a migração.

## Organização

| Arquivo | Responsabilidade |
| --- | --- |
| `src/index.ts` | Roteamento Hono, validação HTTP, CORS e erros no formato da API. |
| `src/pages.ts` | Exportação paginada, relações, busca, home e apresentação de artigos. |
| `src/directory.ts` | Perfis públicos e histórico público de imagens. |
| `src/accounts.ts` | Sessões, login, consultas autenticadas, CSRF, limites, visitas e limpeza programada. |
| `src/article-writes.ts` | Edição, prévia, restauração, controle de versão e atualização atômica dos vínculos. |
| `src/wiki-renderer.ts` | Renderização da marcação wiki compatível e sanitização do HTML gerado. |
| `src/editor-history.ts` | Histórico de artigos e listagem da fila de reprocessamento para administradores. |
| `src/image-edits.ts` | Criação, substituição, remoção e restauração de imagens. |
| `src/media.ts` | Autoriza a chave no D1 antes de ler ou entregar um objeto do R2 privado. |
| `migrations/` | Estrutura normalizada e tabelas de sessão, limites e deduplicação de visitas. |
| `legacy/scripts/export-mysql.php` | Exportação consistente do MySQL, validada contra SQLite antes da importação no D1. |

## Desenvolvimento local

Com Node.js 24, execute na raiz do repositório.

```sh
npm ci
npm run check
npm test
npx wrangler d1 migrations apply mdawiki --local
npm run dev
```

O banco local começa vazio. Use registros fictícios para desenvolvimento. O Wrangler usa armazenamento local com a flag `--local`. O arquivo aponta para os bindings remotos fornecidos para o projeto.

## Homologação no Cloudflare

1. Confirme o acesso ao D1 `mdawiki` e ao bucket privado `mdawiki` informados em `wrangler.jsonc`. Ajuste `ALLOWED_ORIGINS` à origem do front de teste.
2. Aplique as migrations com `npx wrangler d1 migrations apply mdawiki --remote` a partir da raiz do repositório.
3. Conecte um Worker de teste ao repositório com branch de deploy `dev`, diretório raiz vazio ou `.` e comando de deploy `npm run deploy`. Para deploy manual, use o mesmo comando na raiz depois de autenticar o Wrangler.
4. Configure `APP_KEY` como secret com `npx wrangler secret put APP_KEY`. Use uma chave exclusiva de homologação com pelo menos 32 bytes e contas de teste geradas com essa chave.
5. Copie os objetos de teste para o bucket nas chaves existentes, como `articles/<nome>` e `users/<nome>`. Verifique `GET /health`, `GET /v1/statistics` e `GET /media/...` no endereço do Worker de homologação.

## Importação

Use PHP CLI com PDO MySQL e SQLite. Rode a exportação de uma cópia consistente do MySQL com schema normalizado. Use credenciais de leitura e um caminho fora do repositório. O SQL inclui dados de contas e hashes de senha. Em homologação compartilhe apenas dados fictícios ou previamente sanitizados.

```sh
export MYSQL_DSN='mysql:host=127.0.0.1;dbname=mdawiki;charset=utf8mb4'
export MYSQL_USER='usuario_de_leitura'
# Carregue MYSQL_PASSWORD no ambiente por um meio seguro.
php legacy/scripts/export-mysql.php /caminho/privado/mdawiki-data.sql
npx wrangler d1 execute mdawiki --local --file=/caminho/privado/mdawiki-data.sql
```

O exportador preserva IDs e vínculos, valida as restrições no SQLite e imprime as contagens e o tamanho com índices. Ele recusa sobrescrever o arquivo de saída ou gerar instruções acima do limite do D1. Use uma base de destino nova para cada importação. Compare as contagens das tabelas e teste leitura e mídia antes de considerar uma importação remota. O SQL gerado contém somente dados. A estrutura vem das migrations.

## Diferenças nesta etapa

- A busca usa a comparação nativa do SQLite. A equivalência completa de acentos e maiúsculas com o MySQL ainda precisa de validação.
- A leitura usa HTML importado, com substituição dos marcadores de URL e do domínio antigo da API nas referências de mídia. A decoração dinâmica de links de artigos e a integração com material de estudo ainda precisam de portabilidade.
- A entrega R2 cobre as chaves normalizadas de artigos públicos e fotos associadas a contas ativas. O sistema antigo continua responsável pelos fluxos de edição e compatibilidade com outras chaves de mídia.
- Recuperação de senha, edição de perfil, upload avulso e avisos administrativos por e-mail são etapas seguintes da migração.

## Sessões e visitas

O cliente chama `GET /v1/auth/session` e envia o `csrf_token` no cabeçalho `X-CSRF-Token` das escritas. O cookie HTTP-only identifica a sessão. Login e logout renovam o cookie e o token. O D1 armazena o hash do identificador e invalida sessões autenticadas se a senha ou a situação da conta mudar. As respostas de sessão, perfil e leitura autenticada usam `Cache-Control: no-store`.

Contas legadas com MD5 recebem um hash PBKDF2 após o login. O formato PBKDF2 existente depende da mesma `APP_KEY` usada para gerar o hash. Valide o tempo de CPU do PBKDF2 no plano Cloudflare antes de uma virada. Em homologação, use credenciais fictícias. Sessões do PHP e do Worker têm cookies próprios.

`POST /v1/visits` aceita sessões anônimas e autenticadas. O D1 deduplica por sessão e artigo em 30 segundos. Um único cron remove sessões expiradas, janelas antigas de rate limiting e visitas com mais de 31 dias. Aplicar as migrations desta etapa adiciona estruturas com prefixo `worker_`. Para voltar à versão anterior, publique o Worker anterior mantendo essas tabelas e os dados históricos de visitas.

## Escrita de imagens

`PUT /v1/images/:slug` aceita o campo de versão `base_version` e um arquivo em `data`, uma remoção com `clear: true` ou uma restauração com `revision_id`. A rota requer sessão, CSRF e papel de editor. Os arquivos são gravados por SHA-256 no R2. O D1 atualiza a referência com controle de concorrência e grava a revisão e a fila de páginas vinculadas via trigger. O objeto anterior é mantido para servir o histórico.

O Worker já insere as páginas afetadas na fila. Em homologação, o cron tenta reprocessar artigos com marcação compatível. Os avisos administrativos por e-mail ainda precisam do port do serviço de e-mail. Uma disputa de edição pode deixar um arquivo sem vínculo no R2. A rota pública verifica o vínculo antes de servi-lo.

## Escrita de artigos em homologação

Aplique as migrations do D1 antes do deploy. Edição, prévia, restauração e reprocessamento manual seguem as permissões da conta. A fila automática é executada quando `ARTICLE_REBUILD_CRON_ENABLED=true`. A versão de edição faz parte do contrato HTTP. O lote D1 guarda conteúdo, revisão e vínculos na mesma transação e rejeita concorrência com HTTP 409.

O renderizador sanitiza HTML e processa a marcação básica, incluindo títulos, listas, links, categorias, inclusões, imagens e redirecionamentos. Macros de apresentação, tabelas wiki e regras PCRE do PHP exigem validação adicional. Macros desconhecidas e tabelas wiki retornam `unsupported_markup`. O HTML armazenado segue disponível nesses casos. O reprocessamento preserva páginas com erro na fila.

Para rollback dessa etapa, publique a versão anterior do Worker. A coluna de controle é aditiva e compatível com a versão anterior.

## Cadastro e ativação

O Worker implementa `POST /v1/auth/register`, `POST /v1/auth/activate` e `POST /v1/auth/resend`. A conta é criada inativa. Um código de seis dígitos vence em 24 horas e só pode ser consumido uma vez. O reenvio substitui o código anterior. Em caso de falha SMTP após a gravação, a conta permanece inativa e admite reenvio.

Configure `APP_KEY` e os valores `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_FROM`, `SMTP_USERNAME` e `SMTP_PASSWORD` no Worker. Use TLS explícito (`tls`, normalmente porta 587) ou TLS implícito (`ssl`, normalmente porta 465). Mantenha credenciais nos secrets da Cloudflare. As rotas usam a tabela existente `user_activations`.

### Redefinição das senhas importadas

A API usa PBKDF2-SHA256 com 100 mil iterações, salt por senha e a
`APP_KEY` existente como segredo de aplicação. Senhas no formato PHP
precisam ser redefinidas pela tela **Esqueci minha senha**. O usuário
recebe um link válido por 30 minutos e pode escolher uma senha a partir
de dez caracteres. O cadastro aceita seis. A redefinição mantém o cadastro e invalida as sessões
que usavam o hash anterior.

Antes de publicar o Worker, aplique a migração de redefinição de senha no
banco do ambiente de teste e confirme a configuração do SMTP e de
`FRONTEND_URL`. No ambiente configurado neste projeto:

```sh
npx wrangler d1 migrations apply mdawiki --remote
```

### Notificações por e-mail no Worker

`MAIL_ADMIN_TO` recebe avisos de operações administrativas.
`MAIL_USER_COPY_TO` recebe cópias ocultas dos e-mails de conta. Ambas as
listas aceitam vírgula ou ponto e vírgula e ignoram entradas inválidas e
duplicatas. As cópias mantêm o destinatário original no cabeçalho e
ocultam códigos e links de autenticação. Configure as variáveis no
Worker junto das configurações SMTP.

Aplique a migração `0007_mail_outbox.sql` ao D1 antes de publicar. O
Worker registra falhas em `system_error_logs` sem guardar endereços,
credenciais ou conteúdo nesse log. A fila no D1 contém o conteúdo das
notificações opcionais que falharam e usa o cron existente para até
cinco novas tentativas. As notificações são enviadas depois da
gravação. Em caso de falha após aceitação pelo provedor, uma nova
tentativa pode gerar duplicata.

| Evento ou comportamento do PHP | Estado no Worker |
| --- | --- |
| Cadastro, reenvio de código e recuperação | E-mail ao usuário com HTML e texto, mais cópia oculta sem segredos quando configurada. |
| Cadastro, ativação e redefinição concluída | `[user][new]` no cadastro e `[user][update]` após ativação ou troca da senha por recuperação. Perfil, foto e link incluídos quando disponíveis. O perfil público só abre após a ativação. |
| Edição de perfil, foto, senha autenticada e troca de e-mail | Disponíveis no Worker com aviso `[user][update]` após a gravação. A confirmação do endereço usa código por e-mail. |
| Criação, edição e restauração de página | `[page][new]` ou `[page][update]`. Prévia, reprocessamento e alterações sem diferença não geram aviso. |
| Esvaziamento de página existente | `[page][delete]` com o conteúdo anterior. A gravação de uma página vazia continua usando o fluxo existente de edição. |
| Envio, substituição, remoção e restauração de imagem | `[img][new]`, `[img][update]` ou `[img][delete]`, conforme a mudança do arquivo. |
| Exclusão de conta | Fora do fluxo existente. |
| Layout de e-mail | HTML com alternativa em texto. O Worker carrega o logo pelo endereço do front. O PHP usava um anexo embutido, que pode ter melhor suporte em alguns clientes. |
| Falhas de envio opcional | D1 e cron do Worker no lugar da fila em disco do PHP. |

Na comparação de rotas, o Worker também cobre sessão, login, logout,
perfis públicos, consulta e busca de artigos, home, estatísticas,
visitas, editor, histórico, prévia, restauração e reprocessamento de
páginas, além da edição e histórico de imagens. O PHP ainda tem
`GET /v1/users`, `GET /v1/users/{id}` e o upload genérico
`POST /v1/media`, que não têm equivalentes nessas rotas do Worker.
O catálogo do Worker cobre a lista de atualização. Esta comparação é
de rotas e eventos, sem afirmar equivalência de renderização ou de
regras internas em todas as operações.

### Edição de conta no Worker

Aplique `0008_email_changes.sql` ao D1 antes de publicar. As telas atuais
enviam os dados para `PUT /v1/profile`, `POST /v1/profile/photo`,
`POST /v1/profile/password` e `POST /v1/profile/email`.

A alteração de senha exige a senha atual e uma nova senha de pelo menos
dez caracteres. A API renova a sessão atual e invalida as demais.
A alteração de e-mail envia um código para o novo endereço. O usuário
digita esse código no formulário aberto. O código vale por 30 minutos,
é armazenado como hash e só pode ser usado uma vez. Um novo pedido
substitui o código pendente. A troca só ocorre após a confirmação.

### Imagens dentro de artigos

As referências de imagem do wiki usam `GET /media/image/:slug`, que
consulta o arquivo público atual no D1 e no R2. A resposta não é
armazenada em cache, para que substituições, remoções, restaurações e
primeiros envios apareçam no próximo carregamento da imagem. Uma
referência ausente recebe o SVG «Sem imagem», inclusive quando o objeto
não está no R2. Ao apresentar artigos já salvos, a API converte as
imagens vinculadas a `/i/` ou `/imagem@` que usavam endereços antigos.
Esse caminho não depende do cron de reprocessamento nem cria uma
imagem no catálogo só porque ela foi mencionada no artigo.

A migração `0009_pending_page_images.sql` registra referências cujo arquivo
ainda não aparece no catálogo. Depois que uma imagem pública é cadastrada,
o D1 move os vínculos pendentes para `page_media` na mesma operação. A
migração também recupera referências anteriores já gravadas em
`page_templates` quando o nome normalizado ou legado encontra a imagem.
