# MDAWiki API

Nova base em **PHP puro 8.2+**, sem framework, sem Workers e sem migrações. Substitui o conteúdo da implementação anterior; o histórico Git é preservado.

Esta primeira entrega oferece leitura do HTML já processado em `PAGINAS`. Não é a migração completa do motor: listas dinâmicas, redirecionamentos, parser, login, escrita, histórico, mídia e tarefas serão extraídos do `mdawiki-site` nas próximas etapas. Nenhum arquivo de configuração ou segredo do legado é copiado.

## Executar

Requisitos: PHP 8.2+, PDO MySQL e mbstring. Copie `.env.example` para `.env`, ajuste as variáveis e execute:

```sh
php -S 127.0.0.1:8080 -t public public/router.php
php tests/run.php
```

Em produção a raiz do site deve apontar exclusivamente para `public/`. `.env`, `src/`, `tests/` e `config/` ficam fora da raiz pública. HTTPS é responsabilidade da hospedagem. Use uma conta MySQL de leitura nesta etapa.

`DB_CHARSET=latin1` preserva a conexão legada. São preservadas strings que já contêm bytes UTF-8 e convertidas as demais na resposta. Isso precisa ser validado em uma cópia real antes de produção: o schema não revela como todos os registros foram codificados. Não há conversão do banco ou DDL automático.

## Contrato

- `GET /health`: disponibilidade do processo, sem testar o banco.
- `GET /v1/pages?limit=100&after=...`: exportação paginada para geração estática.
- `GET /v1/pages/{slug}`: consulta atual no navegador; slug percent-encoded.
- `OPTIONS`: preflight apenas das origens explicitamente configuradas.

Acesse `/` para conferir as rotas válidas. `/health` verifica somente o processo, não o banco. Erros distinguem rota inválida, busca de artigo, conexão e consulta SQL. Para receber a mensagem original da exceção e o motivo de exclusão pelos filtros, use temporariamente `APP_DEBUG=true` no `.env`. Veja os campos e classificações em [docs/api.md](docs/api.md#diagnóstico-de-erros).

Somente `BUSCA=S`, nível até `PUBLIC_MAX_LEVEL`, sem páginas especiais `page@` e páginas de mídia `imagem@`. Todas as consultas possuem parâmetros. O build usa apenas dados públicos. Listagem ordenada por `IDENT`, com cursor opaco; o navegador consulta por `NOME_URL`, preservando acentos e sublinhados.

API não retorna senhas, tokens, fonte wiki ou dados cadastrais. Leitura não registra acessos, não reprocessa artigos e não executa o cron. HTML é tratado como conteúdo não confiável pelo frontend e sanitizado antes da renderização.

Configure `FRONTEND_URL` e `LEGACY_MEDIA_URL` para expandir os dois placeholders de URL do legado. Outras regras de templates e URLs absolutas antigas serão tratadas na extração do renderer; o adaptador não promete paridade completa nesta etapa.

Mudanças futuras de linguagem ou hospedagem devem manter o contrato em [docs/api.md](docs/api.md). A implementação antiga D1/TypeScript e suas migrations foram retiradas, não aplicadas ao banco legado.
