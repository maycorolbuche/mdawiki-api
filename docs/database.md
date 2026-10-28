# Estrutura inicial do banco

D1 utiliza SQLite: IDs são `INTEGER PRIMARY KEY`, booleanos são inteiros 0/1 e JSON é armazenado em `TEXT`, com validação de objeto JSON. Datas e horários usam texto UTC; datas civis usam YYYY-MM-DD. `updated_at` deve ser atualizado pela aplicação.

| Tabela | Finalidade |
| --- | --- |
| pages | Todos os tipos de páginas, incluindo redirecionamentos. |
| categories | Categorias e hierarquia opcional. |
| page_categories | Relação entre páginas e categorias, com ordem. |
| media | Metadados dos arquivos; os bytes ficam fora do banco. |
| page_media | Relação entre páginas e arquivos utilizados. |
| page_revisions | Versões editoriais completas em snapshot JSON. |
| users | Contas e perfil básico. |
| page_daily_stats | Contagem diária por página. |
| legacy_page_mappings | Correspondência entre identificadores legados e páginas novas. |

Os campos e constraints estão documentados diretamente em `migrations/0001_initial_schema.sql`. Não há tabelas específicas de especialidades, pessoas ou redirecionamentos.

## Contrato planejado para páginas

`type` permanece aberto a tipos futuros. A API deverá validar `metadata` conforme o tipo. Exemplos:

```json
{"club_type":"pathfinders","area":"AR","code":"001","level":1,"introduction_year":1986,"icon_media_id":42}
```

```json
{"birth_date":"1950-04-12","death_date":null,"positions":[]}
```

```json
{"redirect":123}
```

O último exemplo pertence a uma página de `type = redirect`, cujo slug é o endereço antigo. O destino é o ID permanente da página. Referências dentro de JSON não possuem foreign keys: existência dos destinos, ciclos, tipos de metadados e uso de mídias serão validados pela futura camada de aplicação. A tabela `page_media` deve incluir também mídias referenciadas nos metadados para proteger seus vínculos.

## Slugs e renomeação

- O backend gera o slug a partir do título; o título é preservado como recebido.
- Sem `old_slug`, o contrato previsto cria ou atualiza pelo slug gerado.
- Com `old_slug`, localiza e renomeia a página mantendo o ID e cria uma página de redirecionamento no slug antigo, na mesma operação atômica.
- Se o slug não mudar, não cria redirecionamento.
- Colisões com outra página ou redirecionamento retornam conflito. Upserts não devem converter silenciosamente redirecionamentos em artigos.
- Renomear de volta para um alias da própria página exige tratar esse alias explicitamente na operação atômica.

Esses comportamentos ainda não estão implementados. A migration garante unicidade do slug em um único namespace.

## Integridade e escopo

As relações principais utilizam foreign keys. Exclusões de páginas removem vínculos, revisões e estatísticas; mapeamentos legados impedem exclusão física enquanto existirem. Arquivamento deve ser preferido para preservar referências e histórico. Mídias em uso por `page_media` não podem ser excluídas; avatares e capas são anulados quando a mídia é removida. A exclusão de usuários preserva conteúdo e autoria nullable.

Hierarquias de categorias precisam de validação de ciclos na aplicação. Não há triggers para regras de negócio, motor wiki, FTS, permissões, tokens ou filas nesta etapa. A busca será definida com as rotas; `content_text` já está disponível para esse fim. Não há migração automática dos registros legados.
