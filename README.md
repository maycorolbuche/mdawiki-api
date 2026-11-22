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

O front envia base64 diretamente no PUT da página. URLs locais retornadas pelo antigo POST `/v1/media` continuam aceitas por compatibilidade e são convertidas em base64, sem buscar URLs externas. Remover limpa os três campos e os derivados dessa página; restaurar ou reprocessar regenera os arquivos a partir da fonte armazenada, sem depender de um upload antigo. `bin/import-media.php` também importa as pastas de miniaturas e capas do acervo legado.


### E-mails de eventos compatíveis com o legado

A API reutiliza as tabelas existentes `EMAIL_MSG`, `EMAIL_ENVIO`, `EMAIL_CONFIG` e `EMAIL_LOGS`; não há migration nem novas variáveis obrigatórias. Os modelos e os destinatários precisam estar cadastrados nessas tabelas, como no site antigo.

| Evento | Quando é disparado |
| --- | --- |
| `PAGE_ALT` | Criação, alteração ou restauração de artigo/imagem; inclui gravações recuperadas pelo comando local. Reprocessamento automático não representa nova edição e não envia aviso. |
| `USU_CADASTRO_ATIVAR` | Novo cadastro. |
| `USU_CADASTRO_ATIVAR_ENVCODIGO` | Reenvio do código de ativação. |
| `USU_CADASTRO_ATIVADO` | Ativação concluída. |
| `USU_ALTERACAO` | Alteração do perfil ou envio de nova foto. |
| `USU_ALTERACAO_SENHA` | Troca de senha ou redefinição concluída. |
| `USU_ALTERACAO_EMAIL_ENVCODIGO` | Confirmação solicitada para o novo e-mail. |
| `USU_ALTERACAO_EMAIL` | Troca de e-mail concluída. |

Assunto e mensagem por destinatário em `EMAIL_ENVIO` têm precedência quando não estão vazios; caso contrário, usa `EMAIL_MSG`. Para, CC e CCO também vêm de `EMAIL_ENVIO`. O SMTP é selecionado pelo `IDENTIF` do modelo, consultando `EMAIL_CONFIG`; sem configuração específica, utiliza o SMTP do ambiente. Mensagens dos modelos são HTML UTF-8.

As variáveis `<[NOME]>`, `<[EMAIL]>`, `<[EMAIL_NOVO]>`, `<[APELIDO]>`, `<[PAGINA]>`, `<[PAGINA_URL]>`, `<[CONTEUDO_CODE]>`, `<[CONTEUDO]>`, `<[CODIGO]>`, dados de clube/perfil, `<[IP]>`, `<[NAVEGADOR]>`, `<[DATAHORA]>`, `<[LINK]>` e `<[URL_SITE]>` são preenchidas como no legado. LINK usa o endereço da página no novo FRONTEND_URL. Confirme os links fixos dos modelos do banco antes de desativar o domínio antigo; prefira as variáveis de URL. EMAIL_ANTIGO também está disponível no aviso de alteração de e-mail.

Notificações opcionais respeitam `USUARIOS.ENVIA_EMAIL=N`. Os códigos necessários para ativar uma conta ou confirmar novo e-mail continuam sendo enviados mesmo com notificações opcionais desativadas. Se nenhum modelo entregar o código ao destinatário necessário, mantém o envio simples atual como alternativa, sem duplicar uma mensagem pessoal já atendida pelo modelo. A recuperação de senha mantém o link seguro com token e não volta a usar senha provisória em texto.

O envio é tentado na solicitação e os eventos ficam em `EMAIL_LOGS`: `N` pendente, `S` enviado, `E` falha, com data, motivo original e contador de tentativas. Erro no aviso de uma edição não desfaz o conteúdo salvo. Falha no envio obrigatório do código impede a conclusão daquele fluxo e preserva o tratamento transacional existente.

Para retentativas, o comando `bin/maintenance.php` já processa a fila. Também existe um comando separado:

```sh
php /caminho/mdawiki-api/bin/send-mail.php
```

Se a manutenção ainda não estiver agendada, execute o comando de e-mails por cron a cada cinco minutos. Ele processa até 50 registros, aguarda cinco minutos entre tentativas e limita falhas automáticas a dez tentativas. Mensagens novas usam UID `api-...`; o comando não reenvia automaticamente o histórico pendente do site antigo. Não execute o enviador legado sobre a mesma fila simultaneamente. Bloqueios locais por registro evitam duplicação entre processos da API no mesmo servidor.

Confira `EMAIL_LOGS.ENVIADO`, `RETORNO` e `TENTATIVAS_ENVIO` para diagnosticar falhas. Após corrigir a configuração, uma falha esgotada pode ser liberada pelo administrador ajustando o contador/data do registro. Os testes usam transporte simulado: não enviam mensagens reais nem exigem credenciais SMTP.


### Foto de perfil quadrada

`POST /v1/profile/photo` exige uma imagem raster quadrada (PNG, JPEG, GIF ou WebP). O front abre o modal Cropper.js e converte o recorte para PNG de 512×512 antes do envio, inclusive quando a origem escolhida é SVG. A API verifica as dimensões reais antes de gravar o arquivo ou alterar o perfil; uma imagem não quadrada ou SVG sem recorte retorna `422 invalid_profile_photo`.

Os avatares já cadastrados e a importação do acervo são preservados. Essa restrição pertence ao upload de foto de perfil e não se aplica às imagens de artigos. Não há novas variáveis de ambiente ou mudanças de esquema.

## Prévia da base migrada

Em `dev`, aponte `DB_*` para a base importada. A API reconhece a estrutura pelo banco conectado. A base importada atende leitura pública, exportação, busca, início e contadores básicos. Autenticação, edição, histórico, catálogos e reconstrução dos vínculos da base migrada ainda estão em adaptação.
