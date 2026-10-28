import type { Context } from 'hono';
import type { AppEnv } from '../types';
import { PageService } from '../services/PageService';
import { fail } from '../errors';
import { validate } from '../validators/page';

export class PageController {
  static async list(c: Context<AppEnv>) {
    const limit = Number(c.req.query('limit') ?? 20);
    const offset = Number(c.req.query('offset') ?? 0);
    if (!Number.isSafeInteger(limit) || limit < 1 || limit > 100 || !Number.isSafeInteger(offset) || offset < 0) fail(422, 'Paginação inválida.');
    return c.json(await new PageService(c.env.DB).list(limit, offset, c.req.query('type') ?? null));
  }
  static async show(c: Context<AppEnv>) {
    const result = await new PageService(c.env.DB).get(c.req.param('slug'));
    if ('redirect' in result) return c.json(result, 301, { Location: `/pages/${result.redirect}` });
    return c.json(result);
  }
  static async store(c: Context<AppEnv>) { return PageController.write(c); }
  static async update(c: Context<AppEnv>) { return PageController.write(c, c.req.param('slug')); }
  private static async write(c: Context<AppEnv>, slug?: string) {
    if (!c.req.header('content-type')?.toLowerCase().startsWith('application/json')) fail(415, 'Use Content-Type: application/json.');
    const raw = await c.req.text();
    if (raw.length > 1_000_000) fail(413, 'JSON excede o limite de 1 MB.');
    let body: unknown;
    try { body = JSON.parse(raw); } catch { fail(400, 'JSON inválido.'); }
    const result = await new PageService(c.env.DB).save(validate(body), slug);
    return c.json({ data: result.data }, result.status as 200 | 201);
  }
  static async destroy(c: Context<AppEnv>) {
    await new PageService(c.env.DB).delete(c.req.param('slug'));
    return c.body(null, 204);
  }
}
