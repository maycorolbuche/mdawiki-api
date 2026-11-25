import { retryOptionalMail } from './wiki-mail'
import { Hono } from 'hono'
import { failureReason, type RegistrationStage, type SessionStage } from './diagnostics'
import { registration } from './registration'
import { articleWrites, rebuildQueuedArticles } from './article-writes'
import { editorHistory } from './editor-history'
import { imageEdits } from './image-edits'
import { accounts, purgeExpiredData } from './accounts'
import { publicImage, publicUser } from './directory'
import { missingImagePath } from './article-images'
import { missingArticleImage, serveArticleImage, serveMedia } from './media'
import { findPage, home, listPages, searchPages, statistics } from './pages'
import { validSlug, type Env } from './types'

const app = new Hono<{ Bindings: Env; Variables: { registrationStage: RegistrationStage; sessionStage: SessionStage } }>()
const error = (code: string, message: string) => ({ error: { code, message } })
const integer = (value: string, min: number, max: number) =>
  /^\d+$/.test(value) && Number(value) >= min && Number(value) <= max
const origin = (url: string) => new URL(url).origin
const info = { status: 'ok', service: 'mdawiki-api', version: '1', database: 'not_checked' }

/** Aceita somente origens explícitas e mantém a resposta de erro no contrato v1. */
app.use('*', async (c, next) => {
  const requestOrigin = c.req.header('Origin')
  c.header('Vary', 'Origin', { append: true })
  c.header('X-Content-Type-Options', 'nosniff')
  if (requestOrigin) {
    if (!c.env.ALLOWED_ORIGINS.split(',').map(value => value.trim()).includes(requestOrigin)) {
      return c.json(error('origin_not_allowed', 'Origem não autorizada.'), 403)
    }
    c.header('Access-Control-Allow-Origin', requestOrigin)
    c.header('Access-Control-Allow-Credentials', 'true')
  }
  if (c.req.method === 'OPTIONS') {
    c.header('Access-Control-Allow-Methods', 'GET, HEAD, POST, PUT, OPTIONS')
    c.header('Access-Control-Allow-Headers', 'Accept, Content-Type, X-CSRF-Token')
    return c.body(null, 204)
  }
  await next()
})

app.route('/v1', accounts)
app.route('/v1', registration)
app.route('/v1', imageEdits)
app.route('/v1', editorHistory)
app.route('/v1', articleWrites)
app.get('/', c => c.json({ ...info, mode: 'preview' }))
app.get('/health', c => c.json(info))
app.get('/v1/pages', async c => {
  const limit = c.req.query('limit') ?? '100'
  const after = c.req.query('after') ?? ''
  if (!integer(limit, 1, 500) || (after && !/^[1-9]\d{0,18}$/.test(after))) {
    return c.json(error('invalid_pagination', 'Paginação inválida.'), 400)
  }
  return c.json(await listPages(c.env.DB, origin(c.req.url), c.env.FRONTEND_URL.replace(/\/$/, ''), Number(limit), after))
})
app.get('/v1/pages/:slug', async c => {
  const slug = c.req.param('slug')
  if (!validSlug(slug)) return c.json(error('invalid_slug', 'Endereço inválido.'), 400)
  const data = await findPage(c.env.DB, origin(c.req.url), c.env.FRONTEND_URL.replace(/\/$/, ''), slug)
  return data ? c.json({ data })
    : c.json(error('not_found', 'Artigo não encontrado ou indisponível.'), 404)
})
app.get('/v1/search', async c => {
  const term = (c.req.query('q') ?? '').trim()
  const limit = c.req.query('limit') ?? '50'
  const offset = c.req.query('offset') ?? '0'
  if ([...term].length > 100 || !integer(limit, 1, 100) || !integer(offset, 0, 1000000)) {
    return c.json(error('invalid_search', 'Busca ou paginação inválida.'), 422)
  }
  return c.json({ data: await searchPages(c.env.DB, term, Number(limit), Number(offset)) })
})
app.get('/v1/home', async c => c.json({ data: await home(c.env.DB, origin(c.req.url)) }))
app.get('/v1/statistics', async c => c.json({ data: await statistics(c.env.DB) }))
app.get('/v1/public-users/:id', async c => {
  const data = await publicUser(c.env.DB, origin(c.req.url), c.req.param('id'))
  return data ? c.json({ data }) : c.json(error('user_not_found', 'Perfil não encontrado.'), 404)
})
app.get('/v1/images/:slug', async c => {
  const slug = c.req.param('slug')
  const data = validSlug(slug) ? await publicImage(c.env.DB, origin(c.req.url), slug) : null
  return data ? c.json({ data }) : c.json(error('image_not_found', 'Imagem não encontrada.'), 404)
})
app.on(['GET', 'HEAD'], missingImagePath, c => missingArticleImage(c.req.method === 'HEAD'))
app.on(['GET', 'HEAD'], '/media/image/:slug', c => {
  const slug = c.req.param('slug')
  return validSlug(slug) ? serveArticleImage(c.env, slug, c.req.method === 'HEAD')
    : c.json(error('not_found', 'Imagem não encontrada.'), 404)
})
app.on(['GET', 'HEAD'], '/media/:group/:file', async c =>
  await serveMedia(c.env, c.req.param('group'), c.req.param('file'), c.req.method === 'HEAD')
    ?? c.json(error('not_found', 'Arquivo não encontrado.'), 404))

app.notFound(c => c.req.path.startsWith('/v1/') && !['GET', 'HEAD'].includes(c.req.method)
  ? c.json(error('read_only_preview', 'Esta operação ainda não está disponível no Worker.'), 501)
  : c.json(error('route_not_found', 'Rota da API não encontrada.'), 404))
app.onError((failure, c) => {
  const request_id = crypto.randomUUID()
  console.error(JSON.stringify({
    request_id, method: c.req.method, event: 'request_failed',
    stage: c.get('sessionStage') ?? c.get('registrationStage'), reason: failureReason(failure),
  }))
  return c.json({ error: { code: 'service_unavailable', message: 'Falha ao processar a solicitação.', request_id } }, 503)
})

export default {
  fetch: app.fetch,
  scheduled: (event: ScheduledController, env: Env, ctx: ExecutionContext) => {
    ctx.waitUntil(purgeExpiredData(env.DB, Math.floor(event.scheduledTime / 1000)))
    ctx.waitUntil(rebuildQueuedArticles(env))
    if (env.MAIL_ADMIN_TO || env.MAIL_USER_COPY_TO) ctx.waitUntil(retryOptionalMail(env))
  },
}
