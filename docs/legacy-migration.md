# Testar a migração de conteúdo

Este comando importa páginas, revisões e os binários das páginas de imagem, incluindo o histórico. Contas, fotos de perfil, categorias, índices de relações, métricas e rotinas ficam para etapas posteriores. A API continua lendo e escrevendo na base antiga.

## Preparar

Use PHP CLI 8.2+ com `pdo_mysql`, `mbstring` e `dom`. Crie uma base **separada** para o destino e aplique [001_content_and_media.sql](../migrations/001_content_and_media.sql) nela, pelo cliente MySQL ou pelo phpMyAdmin. A origem deve ter `PAGINAS` e `PAGINAS_LOGS`; use um usuário com SELECT na origem. O comando precisa de SELECT, INSERT e UPDATE no destino.

Configure as variáveis de ambiente abaixo (exemplo Bash). Informe as senhas na sessão ou em um arquivo privado fora do Git.

```bash
export LEGACY_DB_DSN='mysql:host=127.0.0.1;dbname=mdawiki_legacy;charset=latin1'
export LEGACY_DB_USER='legacy_reader'
export MIGRATION_DB_DSN='mysql:host=127.0.0.1;dbname=mdawiki_new;charset=utf8mb4'
export MIGRATION_DB_USER='migration_writer'
export MIGRATION_MEDIA_ROOT='/caminho/absoluto/acervo-migrado'
# Defina LEGACY_DB_PASSWORD e MIGRATION_DB_PASSWORD no ambiente.
php bin/migrate-legacy.php
```

A origem deve usar o charset de conexão que funciona para os dados na API atual. O importador usa a mesma heurística de reconhecer UTF-8 válido e converter valores restantes de ISO-8859-1. Confira acentos com amostras reais. Configure o diretório em área privada, fora da raiz HTTP. Os originais SVG são preservados byte a byte; a publicação posterior deve aplicar os mesmos controles de conteúdo SVG usados pela API. Se a origem tiver muito histórico, meça a consulta por IDENT. O índice primário legado começa por NOME_URL_ID e uma cópia de homologação pode se beneficiar de um índice adicional em (IDENT, NOME_URL_ID).

## Reexecutar e conferir

- Pode executar o mesmo comando várias vezes. A página é localizada pelo IDENT e atualizada se DT_ALTERACAO ou o conteúdo da linha mudou, inclusive quando o timestamp é nulo ou se repete. Revisões são conferidas independentemente do timestamp da página.
- O histórico recebe números 1, 2, 3 em ordem de NOME_URL_ID, por página. O número original fica em legacy_revision_number e o ID interno se mantém nas reexecuções. Revisões importadas anteriormente são preservadas caso o legado as remova. Páginas apagadas na origem e revisões sem página correspondente ficam fora da sincronização automática.
- Imagens vêm exclusivamente de CONTEUDO_CODE da página ou revisão. Um campo vazio representa uma imagem sem arquivo. Bytes inválidos interrompem a migração daquela página e aparecem no relatório; as demais páginas continuam. Arquivos com o mesmo SHA-256 reutilizam o mesmo file_id, inclusive entre páginas e revisões. Um arquivo ausente no destino é recriado; divergência de hash é reportada. Miniaturas e capas ficam para a etapa de publicação.
- A gravação no banco é transacional por página. Se a transação falhar depois da gravação do arquivo, a próxima execução reutiliza o binário pelo hash. O bloqueio do MySQL evita dois importadores no mesmo destino. O resumo apresenta páginas inseridas, atualizadas, inalteradas, revisões importadas ou atualizadas e páginas com erro. Código de saída 1 indica falha total ou parcial.
- Para a conferência final, pausar escritores e rodar novamente. PAGINAS usa MyISAM na origem e uma cópia com o site editando pode observar a página e o histórico em momentos diferentes. Compare contagens, slugs, acentos, sequências e arquivos no destino antes de trocar a API.
