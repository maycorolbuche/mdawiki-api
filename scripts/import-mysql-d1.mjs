import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'

const root = fileURLToPath(new URL('../', import.meta.url))
const require = createRequire(import.meta.url)

function run(executable, args, env = process.env) {
  return spawnSync(executable, args, { cwd: root, env, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, windowsHide: true })
}

function exportData(output) {
  const result = run(process.env.PHP_BINARY || 'php', ['legacy/scripts/export-mysql.php', output])
  if (result.error || result.status !== 0) {
    if (result.stderr) process.stderr.write(result.stderr)
    throw new Error('A exportação do MySQL falhou. Confira o PHP CLI, os drivers PDO e a conexão de leitura.')
  }
  process.stdout.write(result.stdout)
  // O exportador só imprime nomes de tabelas, contagens e métricas de validação.
  const counts = new Map([...result.stdout.matchAll(/^([a-z][a-z0-9_]*): (\d+)\r?$/gm)]
    .map(([, table, count]) => [table, Number(count)]))
  if (!counts.has('pages') || !counts.has('users')) throw new Error('O exportador não retornou as contagens esperadas.')
  return counts
}

function databaseClient(database, mode) {
  const wrangler = resolve(require.resolve('wrangler/package.json'), '../bin/wrangler.js')
  const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.toUpperCase().startsWith('MYSQL_')))
  function command(action, args = []) {
    return run(process.execPath, [wrangler, 'd1', ...action, database, mode, ...args], env)
  }
  function query(sql) {
    const result = command(['execute'], ['--json', '--command', sql])
    if (result.error || result.status !== 0) throw new Error('Não foi possível consultar o D1. Confira a autenticação e o nome do banco.')
    const response = JSON.parse(result.stdout)
    if (!Array.isArray(response) || response.some(item => item.success !== true || !Array.isArray(item.results))) {
      throw new Error('O D1 retornou uma resposta inesperada.')
    }
    return response.flatMap(item => item.results)
  }
  function counts(tables) {
    if (!tables.length) return []
    return query(tables.map(table => `SELECT '${table}' AS name, COUNT(*) AS total FROM "${table}"`).join(' UNION ALL '))
  }
  return {
    counts,
    ensureEmpty(tables) {
      const existing = new Set(query("SELECT name FROM sqlite_schema WHERE type = 'table'").map(row => row.name))
      const occupied = counts(tables.filter(table => existing.has(table))).filter(row => row.total > 0)
      if (occupied.length) throw new Error('O destino possui dados em ' + occupied.map(row => row.name).join(', ') + '. Escolha um D1 vazio.')
    },
    migrate() {
      const result = command(['migrations', 'apply'])
      if (result.error || result.status !== 0) {
        if (result.stdout) process.stderr.write(result.stdout)
        if (result.stderr) process.stderr.write(result.stderr)
        throw new Error('Falha ao aplicar as migrations do D1. A importação foi interrompida.')
      }
    },
    import(file) {
      const result = command(['execute'], ['--file', file, '--yes'])
      // Erros do Wrangler podem incluir a instrução SQL que falhou e dados de contas.
      if (result.error || result.status !== 0) {
        throw new Error('A carga do D1 falhou. O SQL foi mantido para diagnóstico. Confira o destino antes de repetir a carga.')
      }
    },
  }
}

try {
  const { values } = parseArgs({ options: {
    output: { type: 'string' }, database: { type: 'string' },
    local: { type: 'boolean' }, remote: { type: 'boolean' }, help: { type: 'boolean' },
  } })
  if (values.help) {
    console.log('Uso: npm run migrate:mysql -- --output <arquivo.sql> [--database <nome-ou-binding> (--local | --remote)]')
  } else {
    if (!values.output || (values.database && Boolean(values.local) === Boolean(values.remote)) ||
        (!values.database && (values.local || values.remote))) {
      throw new Error('Informe --output. Para importar, informe também --database e exatamente um de --local ou --remote.')
    }
    if (values.database && !/^[a-z0-9_-]+$/i.test(values.database)) throw new Error('Nome ou binding do D1 inválido.')
    const mode = values.remote ? '--remote' : '--local'
    const db = values.database ? databaseClient(values.database, mode) : null
    const output = resolve(values.output)
    const expected = exportData(output)
    console.log('SQL validado em SQLite e salvo em ' + output)
    if (db) {
      console.log('Destino D1: ' + values.database + ' (' + mode + ')')
      const tables = [...expected.keys()]
      db.ensureEmpty(tables)
      db.migrate()
      db.ensureEmpty(tables)
      db.import(output)
      const imported = new Map(db.counts(tables).map(row => [row.name, Number(row.total)]))
      const differences = tables.filter(table => expected.get(table) !== imported.get(table))
      if (differences.length) throw new Error('Contagens divergentes após importar: ' + differences.join(', '))
      console.log('Importação conferida: ' + tables.length + ' tabelas com contagens iguais às da origem.')
    }
  }
} catch (error) {
  console.error(error.message)
  process.exitCode = 1
}
