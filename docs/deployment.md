# Publicar a API independente

O frontend depende somente desta API. Não há includes, chamadas HTTP ou cron executado no `mdawiki-site`. O schema existente é mantido; não execute os arquivos `tests/schema.*.sql` no banco real.

1. Instale PHP 8.2+ com PDO MySQL, mbstring, DOM, sessões e suporte a imagens. Rode `composer install --no-dev --optimize-autoloader` e publique também `vendor/`.
2. A raiz HTTP deve ser **somente `public/`**. Preserve `.env`, `var/` e o acervo entre deploys. A conta MySQL agora precisa de SELECT, INSERT, UPDATE e DELETE nas tabelas existentes; não precisa de ALTER/CREATE.
3. Configure `.env` a partir do exemplo. Gere `APP_KEY` uma vez com `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, guarde fora do Git e inclua nos backups. Configure `FRONTEND_URL` com a URL do novo frontend e `MEDIA_PUBLIC_URL` com a URL desta API seguida de `/media`.
4. Liste as origens exatas em `ALLOWED_ORIGINS`, sem barra final. Em produção use HTTPS e `SESSION_SECURE=true`. Para PHP local HTTP use `SESSION_SECURE=false` e inclua a origem/porta do Vite. Não use `*` com contas/sessões.
5. Configure `SMTP_*` para o envio de e-mails.
6. Preserve o diretório de mídia da instalação, incluindo `articles/` e `users/`.
7. Agende `php /caminho/mdawiki-api/bin/maintenance.php` a cada cinco minutos usando o PHP correto da hospedagem. Ele reprocessa até 100 páginas marcadas, incluindo dependências de includes/imagens, e limpa limitadores expirados. Sitemap e HTML estático são gerados pelo build do frontend; home, estatísticas e listas consultam o banco atual.
8. Publique/recompile o frontend depois de esta API estar funcionando. Valide login, edição/prévia, histórico, recuperação por e-mail e uma imagem antes de desligar o serviço antigo.

O GitHub Pages deve usar um domínio do mesmo site da API, por exemplo `site.mda.wiki.br` e `api.mda.wiki.br`. No endereço `github.io`, navegadores que bloqueiam cookies de terceiros podem impedir autenticação entre sites. O front continua estático; autenticação é feita por cookie HttpOnly na API, com CORS explícito, credenciais e token CSRF.

## Escritas e recuperação

PAGINAS usa MyISAM no schema original; uma transação não pode prometer rollback atômico dessa tabela. O salvamento usa lock por página no armazenamento da API, versão otimista para rejeitar conflitos, revisões em PAGINAS_LOGS e um journal privado em `var/pending/`. Uma falha deixa a intenção recuperável e impede outro salvamento de apagar essa intenção. Rode `php bin/recover-pages.php` para reaplicar pendências; a recuperação acrescenta uma revisão, mantendo o histórico. Faça backup do banco, APP_KEY, armazenamento de mídia e pendências juntos.

As senhas MD5 existentes são aceitas e atualizadas após login para um formato versionado PBKDF2-HMAC-SHA256 (600 mil iterações, salt individual derivado de APP_KEY, 116 bits armazenados) que cabe em SENHA varchar(32). Novas senhas usam esse formato; não há DDL. O PHP antigo não reconhece esse formato: voltar a usá-lo para autenticação exigiria redefinição de senha. A sessão expira em 12 horas, ou 30 dias com “manter conectado”; troca/redefinição de senha invalida as outras sessões na próxima consulta.

Uploads novos aceitam PNG, JPEG, GIF, WebP e SVG sanitizado até 5 MB (20 megapixels para raster). SVGs existentes são copiados e servidos com CSP sandbox. Uploads de SVG com conteúdo ativo são rejeitados; restaurações de revisões removem esse conteúdo. Dados pessoais de usuários são exclusivos do titular ou de administradores nível 5.

## Validação

`php tests/run.php` verifica o contrato público. `php tests/features.php` usa SQLite em memória. O CI também executa os fluxos no MySQL 8 com as definições originais das tabelas (incluindo MyISAM, latin1 e SENHA varchar(32)), sem usar dados de produção. Os testes cobrem autenticação, CSRF, permissões, hashes, e-mail simulado, restauração, relações, templates e consultas das listas. Credenciais SMTP e bytes dos registros reais ainda precisam da validação na hospedagem.
