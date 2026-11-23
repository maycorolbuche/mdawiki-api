# MDAWiki API

API independente em PHP puro 8.2+, sem framework e sem alterações no schema legado. O frontend Vue usa esta API para leitura, contas, edição, prévia, histórico/restauração, busca, listas, estatísticas, diretório administrativo e imagens.

Leia **[a publicação e a migração do acervo](docs/deployment.md)** antes de desligar o sistema antigo. O backend não executa arquivos nem faz chamadas HTTP para ele.

```sh
composer install
cp .env.example .env
# Configure banco, APP_KEY, origens, SMTP e URLs.
php -S 127.0.0.1:8080 -t public public/router.php
php tests/run.php
php tests/features.php
```

Em produção a raiz HTTP deve apontar exclusivamente para `public/`. `.env`, `src`, `vendor` e `var` ficam fora da raiz pública. A conta do banco precisa de leitura e escrita nas tabelas existentes. Instalação não executa DDL.

- `GET /v1/pages`, `/v1/pages/{slug}`: exportação pública e artigos, incluindo relações e redirecionamentos.
- `/v1/auth/*`: sessão/CSRF, login, logout, cadastro, ativação, recuperação e redefinição de senha.
- `/v1/profile`, `/password`, `/email`, `/photo`: perfil e alterações verificadas.
- `GET /v1/view/{slug}`: leitura autenticada com permissões; nunca exportada estaticamente.
- `GET /v1/pages/{slug}/editor`, `/history`; `PUT /v1/pages/{slug}`; `POST .../preview`, `/restore`, `/rebuild`.
- `GET /v1/search`, `/catalog`, `/statistics`, `/home`; `/v1/users` e `/users/{id}` exclusivos de administradores nível 5.
- `POST /v1/media`, `/v1/visits`; `GET /media/{grupo}/{arquivo}` serve somente imagens do armazenamento da API.

Escritas exigem origem autorizada, cookie de sessão e `X-CSRF-Token`; possuem limites de tentativas. Perfis não aceitam alteração de nível/ativação por atribuição em massa. E-mails usam PHPMailer/SMTP próprio (ou configurações existentes no banco) e links do novo frontend. Senhas antigas são atualizadas após login sem alargar a coluna SENHA.

O código wiki é processado aqui: regras TAGS, includes limitados, tabelas/listas, templates de especialidades/biografias/imagens/vídeos, links, categorias e subtítulos. As rotinas de apresentação reaproveitadas estão isoladas em `wiki-presentation.php`, sem SQL ou dependência de arquivos do site antigo. As tabelas de índices são atualizadas ao salvar; dependências são reprocessadas pelo comando de manutenção.

Use `APP_DEBUG=true` temporariamente para os detalhes originais de erros de banco; `/health` verifica o processo, não a conexão. O contrato, os filtros e o diagnóstico estão em [docs/api.md](docs/api.md). Valide o charset com os bytes do banco real; o schema sozinho não informa todas as conversões históricas.



### Persistência das páginas de imagem (compatibilidade com o legado)

Ao salvar uma página `imagem@...`, a API mantém o formato de `geraImagem`:

- `PAGINAS.CONTEUDO_CODE`: data URI da imagem em base64 (também preservado nas revisões).
- `PAGINAS.CONTEUDO_TXT`: caminho relativo do arquivo principal, como `@imgs_wiki/imagem@exemplo.png`.
- `PAGINAS.CONTEUDO`: JSON com `tipo`, `url`, `url_mini`, `url_cp`, `w`, `h`, `w_mini`, `h_mini`, `w_cp`, `h_cp`, `size`, `size_mini` e `size_cp`.

As imagens raster são limitadas a 500×500, a miniatura a 100×100 e a capa é ampliada quando menor que 200×200, preservando a proporção conforme o cálculo do legado. A fonte base64 é atualizada quando houver redução. SVGs preservam a representação vetorial e passam pela validação já usada pela API.

Os arquivos ficam em `MEDIA_ROOT/wiki`, `MEDIA_ROOT/wiki_mini` e `MEDIA_ROOT/wiki_cp`, servidos por `/media/wiki/...`, `/media/wiki_mini/...` e `/media/wiki_cp/...`. Os caminhos `@imgs_wiki*` continuam gravados no banco e são convertidos em URLs públicas somente na leitura. Esses diretórios precisam permitir escrita pelo PHP. Habilite **GD** no PHP para gerar as versões raster; o Composer e o CI incluem essa extensão. Não há nova variável de ambiente nem alteração de esquema.

O front envia base64 diretamente no PUT da página. URLs locais retornadas pelo antigo POST `/v1/media` continuam aceitas por compatibilidade e são convertidas em base64, sem buscar URLs externas. Remover limpa os três campos e os derivados dessa página; restaurar ou reprocessar regenera os arquivos a partir da fonte armazenada, sem depender de um upload antigo.


### E-mails da API

Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE` (`tls`, `ssl` ou `none`), `SMTP_USERNAME`, `SMTP_PASSWORD` e `SMTP_FROM` no ambiente da API. O layout HTML inclui o logo do MDAWiki e uma versão em texto.

`MAIL_USER_COPY_TO` recebe cópias ocultas dos e-mails de conta, em uma entrega separada com o destinatário original no cabeçalho `X-Original-To`. Códigos e links de acesso ficam ocultos na cópia. `MAIL_ADMIN_TO` recebe avisos de criação e atualização de páginas, publicação, alteração e remoção de arquivos de imagem, cadastro e alterações do usuário. Ambas as variáveis são opcionais e aceitam endereços separados por vírgula ou ponto e vírgula.

Os assuntos usam `[page][new|update]`, `[img][new|update|delete]` e `[user][new|update]`. Reprocessamento e gravações sem mudança não geram aviso. Falhas nas cópias e notificações opcionais entram na fila em `STORAGE_DIR/mail-outbox`, sem reverter o que foi salvo. Falha no envio obrigatório mantém o tratamento transacional dos fluxos de conta.

A migração `004_drop_mail_configuration.sql` remove as três tabelas de configuração de e-mail na base normalizada. A API deixou de consultar o SMTP e os modelos legados. O cron existente (`bin/maintenance.php` ou `bin/send-mail.php`) processa as novas falhas após cinco minutos, com até dez tentativas por mensagem. A fila antiga no banco fica fora desse processamento.

### Cadastro na base migrada

Em uma instalação existente, execute `php bin/migrate-normalized-registration.php` no banco migrado para habilitar cadastro, ativação e reenvio de código. As contas novas usam o AUTO_INCREMENT de `users.id`. IDs existentes são preservados ao atualizar a instalação.

### Logs de erros

Em uma instalação existente, execute `php bin/migrate-system-error-logs.php` uma vez para criar `system_error_logs` no banco da API. Falhas de e-mail, inclusive nas retentativas do cron, registram data, etapa, código do erro e metadados de diagnóstico. A tabela não armazena credenciais, destinatários nem o conteúdo da mensagem.

```sql
SELECT id, created_at, error_code, message, context
FROM system_error_logs
WHERE component = 'mail'
ORDER BY id DESC
LIMIT 30;
```

### Foto de perfil quadrada

`POST /v1/profile/photo` exige uma imagem raster quadrada (PNG, JPEG, GIF ou WebP). O front abre o modal Cropper.js e converte o recorte para PNG de 512×512 antes do envio, inclusive quando a origem escolhida é SVG. A API verifica as dimensões reais antes de gravar o arquivo ou alterar o perfil; uma imagem não quadrada ou SVG sem recorte retorna `422 invalid_profile_photo`.

Os avatares já cadastrados e a importação do acervo são preservados. Essa restrição pertence ao upload de foto de perfil e não se aplica às imagens de artigos. Não há novas variáveis de ambiente ou mudanças de esquema.

## Prévia da base migrada

Em `dev`, aponte `DB_*` para a base importada. A API reconhece a estrutura pelo banco conectado. A base importada atende leitura pública, exportação, busca, início e contadores básicos. Autenticação, edição, histórico, catálogos e reconstrução dos vínculos da base migrada ainda estão em adaptação.


A home da base migrada usa visitas com horário para calcular os rankings móveis de 24 horas, 7 e 30 dias. Em instalações existentes, execute `php bin/migrate-page-visits.php`. A coleta começa após a implantação, pois os contadores históricos não têm horário individual. O cron de manutenção remove registros com mais de 31 dias.

O card **Nos ajude com esses conteúdos** sorteia até dez destinos internos inexistentes de páginas públicas buscáveis. Em instalações existentes, execute `php bin/migrate-page-missing-links.php` e, com o código novo publicado, `php bin/rebuild-normalized-pages.php` para preencher os vínculos dos artigos existentes. Novas gravações mantêm a tabela atualizada.
