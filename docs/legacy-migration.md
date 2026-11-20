# Testar a migração do legado

O comando importa as 29 tabelas físicas do dump legado, incluindo páginas, revisões, usuários, mídia, relações, configurações e históricos. A API continua conectada ao banco legado durante os testes. Views e rotinas SQL são objetos derivados e ficam fora da cópia de registros.

## Preparar

Use PHP CLI 8.2+ com `pdo_mysql`, `mbstring` e `dom`. Crie uma base **separada** para o destino. O comando aplica [001_content_and_media.sql](../migrations/001_content_and_media.sql) e [002_remaining_legacy_tables.sql](../migrations/002_remaining_legacy_tables.sql) automaticamente no destino antes de copiar os dados. O SQL cria as tabelas ausentes e pode ser reaplicado. Use um usuário com SELECT nas tabelas legadas na origem. O comando precisa de CREATE, REFERENCES, SELECT, INSERT e UPDATE no destino.

A origem usa o mesmo `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `DB_CHARSET` da API. A sessão do importador na origem é somente leitura. Configure somente os dados do destino no `.env` ou no ambiente do terminal. O exemplo abaixo usa Bash.

```bash
export MIGRATION_DB_HOST='127.0.0.1'
export MIGRATION_DB_PORT='3306'
export MIGRATION_DB_DATABASE='mdawiki_new'
export MIGRATION_DB_USERNAME='migration_writer'
# Defina MIGRATION_DB_PASSWORD no ambiente ou no .env.
php bin/migrate-legacy.php
```

O destino usa `utf8mb4`; host e porta assumem `127.0.0.1` e `3306` quando omitidos.

O comando usa a configuração de mídia da API. Os arquivos usam o nome `<sha256>.<extensão>` sob `avatars/` (usuários) ou `articles/` (páginas e revisões), diretórios criados em `var/media` por padrão, ou no diretório `MEDIA_ROOT` já configurado no ambiente ou no `.env`.

A origem deve usar o charset de conexão que funciona para os dados na API atual. O importador usa a mesma heurística de reconhecer UTF-8 válido e converter valores restantes de ISO-8859-1. Confira acentos com amostras reais. Configure o diretório em área privada, fora da raiz HTTP. Os originais SVG são preservados byte a byte; a publicação posterior deve aplicar os mesmos controles de conteúdo SVG usados pela API. Se a origem tiver muito histórico, meça a consulta por IDENT. O índice primário legado começa por NOME_URL_ID e uma cópia de homologação pode se beneficiar de um índice adicional em (IDENT, NOME_URL_ID).

Se o MySQL retornar `1045`, o comando indica se a conexão rejeitada foi a origem (`DB_*`) ou o destino (`MIGRATION_DB_*`). Confira as credenciais e a permissão da conta para conectar a partir do host do terminal.

## Contas e histórico

- Os IDs dos usuários, níveis de acesso e status de ativação são preservados. Hashes MD5 aceitos pela API são copiados. Hashes `p2# Testar a migração do legado

O comando importa as 29 tabelas físicas do dump legado, incluindo páginas, revisões, usuários, mídia, relações, configurações e históricos. A API continua conectada ao banco legado durante os testes. Views e rotinas SQL são objetos derivados e ficam fora da cópia de registros.

## Preparar

Use PHP CLI 8.2+ com `pdo_mysql`, `mbstring` e `dom`. Crie uma base **separada** para o destino. O comando aplica [001_content_and_media.sql](../migrations/001_content_and_media.sql) e [002_remaining_legacy_tables.sql](../migrations/002_remaining_legacy_tables.sql) automaticamente no destino antes de copiar os dados. O SQL cria as tabelas ausentes e pode ser reaplicado. Use um usuário com SELECT nas tabelas legadas na origem. O comando precisa de CREATE, REFERENCES, SELECT, INSERT e UPDATE no destino.

A origem usa o mesmo `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `DB_CHARSET` da API. A sessão do importador na origem é somente leitura. Configure somente os dados do destino no `.env` ou no ambiente do terminal. O exemplo abaixo usa Bash.

```bash
export MIGRATION_DB_HOST='127.0.0.1'
export MIGRATION_DB_PORT='3306'
export MIGRATION_DB_DATABASE='mdawiki_new'
export MIGRATION_DB_USERNAME='migration_writer'
# Defina MIGRATION_DB_PASSWORD no ambiente ou no .env.
php bin/migrate-legacy.php
```

O destino usa `utf8mb4`; host e porta assumem `127.0.0.1` e `3306` quando omitidos.

O comando usa a configuração de mídia da API. Os arquivos usam o nome `<sha256>.<extensão>` sob `avatars/` (usuários) ou `articles/` (páginas e revisões), diretórios criados em `var/media` por padrão, ou no diretório `MEDIA_ROOT` já configurado no ambiente ou no `.env`.

A origem deve usar o charset de conexão que funciona para os dados na API atual. O importador usa a mesma heurística de reconhecer UTF-8 válido e converter valores restantes de ISO-8859-1. Confira acentos com amostras reais. Configure o diretório em área privada, fora da raiz HTTP. Os originais SVG são preservados byte a byte; a publicação posterior deve aplicar os mesmos controles de conteúdo SVG usados pela API. Se a origem tiver muito histórico, meça a consulta por IDENT. O índice primário legado começa por NOME_URL_ID e uma cópia de homologação pode se beneficiar de um índice adicional em (IDENT, NOME_URL_ID).

Se o MySQL retornar `1045`, o comando indica se a conexão rejeitada foi a origem (`DB_*`) ou o destino (`MIGRATION_DB_*`). Confira as credenciais e a permissão da conta para conectar a partir do host do terminal.

 exigem a mesma `APP_KEY` no ambiente que passará a autenticar pela nova base.
- Hash ausente ou em formato incompatível fica vazio. Contas ativas usam a opção de redefinição por e-mail para cadastrar uma senha. Contas inativas seguem o fluxo de ativação. A migração preserva a senha já gravada no destino nas reexecuções. Tokens e senhas provisórias devem ser emitidos novamente.
- Avatares em Base64 passam pela mesma deduplicação das imagens. Os caminhos antigos são preservados como referência para registros sem Base64.
- Relações preservam as chaves legadas para futura adaptação do backend. Presença, cache e histórico de e-mails ficam como registros legados, sem reativar sessões ou reenviar mensagens. Tabelas sem chave primária usam hash da linha e número da ocorrência para manter duplicatas legítimas. Exclusões na origem ficam fora da sincronização automática.
- Logs de visitas e entregas podem ser grandes. O comando lê as linhas em fluxo e apresenta um resumo por tabela. Faça o ensaio com uma cópia e confira o tempo antes de escolher a janela final.

## Reexecutar e conferir

- Pode executar o mesmo comando várias vezes. A página é localizada pelo IDENT e atualizada se DT_ALTERACAO ou o conteúdo da linha mudou, inclusive quando o timestamp é nulo ou se repete. Revisões são conferidas independentemente do timestamp da página.
- O histórico recebe números 1, 2, 3 em ordem de NOME_URL_ID, por página. O número original fica em legacy_revision_number e o ID interno se mantém nas reexecuções. Revisões importadas anteriormente são preservadas caso o legado as remova. Páginas apagadas na origem e revisões sem página correspondente ficam fora da sincronização automática.
- Imagens vêm exclusivamente de CONTEUDO_CODE da página ou revisão. Um campo vazio representa uma imagem sem arquivo. Bytes inválidos interrompem a migração daquela página e aparecem no relatório; as demais páginas continuam. Arquivos da mesma pasta com o mesmo SHA-256 reutilizam o mesmo file_id, inclusive entre páginas e revisões. Um arquivo ausente no destino é recriado; divergência de hash é reportada. Miniaturas e capas ficam para a etapa de publicação.
- A gravação no banco é transacional por página. Se a transação falhar depois da gravação do arquivo, a próxima execução reutiliza o binário pelo hash. O bloqueio do MySQL evita dois importadores no mesmo destino. O resumo apresenta páginas inseridas, atualizadas, inalteradas, revisões importadas ou atualizadas e páginas com erro. Código de saída 1 indica falha total ou parcial.
- Para a conferência final, pausar escritores e rodar novamente. PAGINAS usa MyISAM na origem e uma cópia com o site editando pode observar a página e o histórico em momentos diferentes. Compare contagens, slugs, acentos, sequências e arquivos no destino antes de trocar a API.

### Erros da importação

Consulte `migration_errors` no banco de destino pelo ID da execução exibido no terminal. Cada registro guarda tabela, chave de origem, campo quando identificado, motivo e data. Foto inválida cancela a importação daquele usuário nessa execução, mantendo os registros previamente importados. Corrija a origem e reexecute. Erros anteriores permanecem no histórico.

Em destinos já importados, a primeira execução requer também permissão ALTER em `files` para permitir um mesmo hash nas duas pastas. A reexecução recria os arquivos pelos dados de origem e atualiza os vínculos. Os arquivos antigos ficam no disco.
