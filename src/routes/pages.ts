import { Hono, type Context } from 'hono';
import { bySlug, detail, fail, save, validate } from '../pages';
import type { AppEnv } from '../types';

export const pages = new Hono<AppEnv>();

pages.get('/', async (c) => {
  const limit = Number(c.req.query('limit') ?? 20);
  const offset = Number(c.req.query('offset') ?? 0);
  if (!Number.isSafeInteger(limit) || limit < 1 || limit > 100 || !Number.isSafeInteger(offset) || offset < 0) fail(422, 'Paginação inválida.');
  const type = c.req.query('type') ?? null;
  const rows = await c.env.DB.prepare("SELECT * FROM pages WHERE type <> 'redirect' AND (? IS NULL OR type=?) ORDER BY id LIMIT ? OFFSET ?").bind(type, type, limit, offset).all<Record<string, any>>();
  return c.json({ data: await Promise.all(rows.results.map(row => detail(c.env.DB, row))), pagination: { limit, offset } });
});

pages.get('/:slug', async (c) => {
  const row = await bySlug(c.env.DB, c.req.param('slug'));
  if (!row) return c.json({ error: 'Página não encontrada.' }, 404);
  if (row.type === 'redirect') {
    const dest = await c.env.DB.prepare("SELECT slug FROM pages WHERE id=? AND type<>'redirect'").bind(JSON.parse(row.metadata).redirect).first<{slug:string}>();
    if (!dest) return c.json({ error: 'Destino não encontrado.' }, 404);
    return c.json({ redirect: dest.slug }, 301, { Location: `/pages/${dest.slug}` });
  }
  return c.json({ data: await detail(c.env.DB, row) });
});

async function parseBody(c: Context<AppEnv>) {
  if (!c.req.header('content-type')?.toLowerCase().startsWith('application/json')) fail(415, 'Use Content-Type: application/json.');
  const raw = await c.req.text();
  if (raw.length > 1_000_000) fail(413, 'JSON excede o limite de 1 MB.');
  let body: unknown;
  try { body = JSON.parse(raw); } catch { fail(400, 'JSON inválido.'); }
  return validate(body);
}

pages.post('/', async (c) => { const result = await save(c.env.DB, await parseBody(c)); return c.json({ data: result.data.data }, result.status as 200 | 201); });
pages.put('/:slug', async (c) => { const result = await save(c.env.DB, await parseBody(c), c.req.param('slug')); return c.json({ data: result.data.data }, result.status as 200 | 201); });

pages.delete('/:slug', async (c) => {
  const row = await bySlug(c.env.DB, c.req.param('slug'));
  if (!row) return c.json({ error: 'Página não encontrada.' }, 404);
  await c.env.DB.batch([
    c.env.DB.prepare("DELETE FROM pages WHERE type='redirect' AND json_extract(metadata,'$.redirect')=?").bind(row.id),
    c.env.DB.prepare('DELETE FROM pages WHERE id=?').bind(row.id),
  ]);
  return c.body(null, 204);
});
