# Trazer os dados da base legada

Execute na instalação da API nova. No `.env` ou no ambiente do terminal, use `DB_*` para a base **nova** e `MIGRATION_DB_*` para a base **legada**.

```dotenv
MIGRATION_DB_HOST=localhost
MIGRATION_DB_PORT=3306
MIGRATION_DB_DATABASE=mdawiki_legacy
MIGRATION_DB_USERNAME=migration_reader
MIGRATION_DB_PASSWORD=
MIGRATION_DB_CHARSET=latin1
```

A origem é acessada em modo somente leitura. Ajuste `MIGRATION_DB_CHARSET` conforme a conexão com o legado. O destino usa `utf8mb4`. Crie previamente a base de destino como uma base separada. Use PHP CLI com `pdo_mysql`, `mbstring`, `dom` e `intl`. A conta de origem precisa de permissão SELECT. A de destino precisa de CREATE, REFERENCES, SELECT, INSERT, UPDATE e DELETE. Bases de destino antigas com índice único em `files.sha256` também precisam de ALTER.

```bash
php bin/migrate-legacy.php
```

O terminal mostra as etapas, o número de registros processados e os erros. A rotina cria as tabelas ausentes e importa páginas, revisões, mídias, usuários, comentários, configurações, níveis, regras wiki e modelos de e-mail. Depois, usa a mesma rotina de `php bin/rebuild-normalized-pages.php` para renderizar as páginas e reconstruir seus vínculos, sem criar revisões. Logs operacionais e filas ficam fora da migração.

As mídias são geradas do Base64 original sob `articles/` e `users/`, no `MEDIA_ROOT` da API nova. O padrão é `var/media`. Cada arquivo usa o hash SHA-256 no nome.

## Reexecutar

O mesmo comando pode ser repetido. As tabelas removidas são recriadas e os arquivos de mídia ausentes são regenerados. Tabelas existentes não são apagadas. O importador atualiza os registros legados, reaproveita IDs e mídias já importados e preserva senhas já gravadas no destino. A atualização das páginas mantém a numeração de revisões. Páginas excluídas no legado não são apagadas automaticamente no destino.

A rotina termina com código 1 se houver falhas de importação ou reprocessamento. Erros por registro da importação ficam em `migration_errors`; filtre pelo ID da execução exibido no terminal. Falhas do reprocessamento são exibidas com o slug. Após corrigir a causa, execute novamente.

## Imagens e endereços reservados

Em uma base de destino vazia, os registros legados `imagem@...` entram em `images`, as versões entram em `image_revisions` e os vínculos regenerados em `page_media` apontam para `images.id`. O endereço público da imagem é `/i/<slug>`. A importação registra como falha páginas comuns com identificador de um único caractere, reservado ao sistema.

Páginas legadas com prefixo `doc@` no identificador ou na URL e suas revisões ficam fora da importação.

Para o esquema de autoria por ID, use uma base de destino vazia. O importador carrega os usuários antes do conteúdo e resolve a autoria por e-mail durante a importação. Guarda os IDs em created_by e updated_by. Se o autor não puder ser identificado, o vínculo fica vazio.

A migração de perfis exige o esquema novo em uma base de destino vazia. O identificador público é aleatório e estável nas reexecuções, e `avatar_filename` guarda o arquivo em `users/`.
