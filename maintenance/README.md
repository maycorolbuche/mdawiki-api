# Limpeza única de revisões consecutivas idênticas

Arquivo: `cleanup-duplicate-history.sql`. Execute no banco MySQL/MariaDB da wiki, pelo cliente SQL ou phpMyAdmin. Não precisa instalar procedure, cron, publicar front ou mudar o esquema das tabelas do sistema.

## Simular

1. Selecione o banco correto. Reserve uma janela sem edições, pois o SQL bloqueia gravações no histórico enquanto trabalha. Requer permissão para criar tabelas/índices e `LOCK TABLES`.
2. Execute **o arquivo inteiro na mesma conexão**, com `SET @mdawiki_apply_cleanup = 0` (padrão).
3. Consulte os resultados: duplicadas por artigo, totais antes/depois e removidas (zero na simulação). A tabela `MDAWIKI_HISTORY_DUPLICATES_01` contém as chaves candidatas; `MDAWIKI_HISTORY_BACKUP_01` contém cópia completa do histórico.

## Executar

1. Após conferir a simulação, use uma nova cópia do arquivo. Troque **todas** as ocorrências de `_01` por `_02`, para criar tabelas novas sem sobrescrever o backup da simulação.
2. Troque `SET @mdawiki_apply_cleanup = 0` por `SET @mdawiki_apply_cleanup = 1`.
3. Execute novamente **o arquivo inteiro na mesma conexão**. Ele tira uma cópia nova do histórico, recalcula os candidatos e só então exclui. Confira `revisoes_removidas` e `historico_depois`.
4. Guarde/exporte `MDAWIKI_HISTORY_BACKUP_02` e `MDAWIKI_HISTORY_DUPLICATES_02`: a recuperação abaixo usa as duas tabelas. Só apague essas tabelas quando não precisar mais de recuperação.

O SQL falha se os nomes de backup já existirem. Isso é intencional. Em caso de erro, não continue executando trechos isolados nem apague backups: encerre a conexão para liberar os bloqueios e examine os resultados. Um timeout pode deixar uma cópia parcial ou uma execução concluída sem o resultado exibido.

## Critério

Ordenação por `NOME_URL_ID`, dentro de cada `IDENT`, preservando a primeira revisão de cada sequência. Compara `NOME` e `CONTEUDO_CODE` byte a byte, sem comparar autor/data ou HTML renderizado. A-A-A-B-B-A vira A-B-A. Revisões de artigos diferentes nunca se misturam. Null não equivale a texto vazio. Não altera `PAGINAS`, datas, autores, relações, arquivos ou notificações e não renumera as revisões restantes.

Se a última revisão for duplicada, ela também é removida. Como a API calcula a próxima numeração por `MAX(NOME_URL_ID) + 1`, números removidos do fim podem ser reutilizados por futuras edições. Por isso, exporte o backup se houver referências externas às revisões excluídas. Nenhum número de revisão mantida é alterado.

## Recuperar as linhas excluídas

Com as edições pausadas, execute (ajuste `_02` para a execução correta):

```sql
INSERT INTO PAGINAS_LOGS
SELECT backup.*
FROM MDAWIKI_HISTORY_BACKUP_02 AS backup
JOIN MDAWIKI_HISTORY_DUPLICATES_02 AS removida
  ON removida.IDENT = backup.IDENT
 AND removida.NOME_URL_ID = backup.NOME_URL_ID;
```

A chave primária bloqueia conflitos com revisões criadas depois. Se houver conflito, não use `REPLACE` nem sobrescreva registros: compare as linhas antes de recuperar. A recuperação só reinsere histórico e não modifica a página atual.
