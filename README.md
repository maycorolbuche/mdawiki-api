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
