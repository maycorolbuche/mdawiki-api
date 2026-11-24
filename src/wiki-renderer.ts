import { articleImagePath } from './article-images'
import sanitizeHtml from 'sanitize-html'
import { decodeHTML } from 'entities'
import { identifier, legacyIdentifier } from './pages'
import { query } from './types'

export class WikiError extends Error {
  constructor(readonly code: string, message: string) { super(message) }
}

const escape = (value: string) => value.replace(/[&<>"']/g, char =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] ?? char)
const strip = (value: string) => decodeHTML(sanitizeHtml(value, { allowedTags: [], allowedAttributes: {} })).trim()
type Include = { name: string; section: string }
type Link = { name: string; fragment: string; label: string }
type ImageRef = { identifier: string; legacy: string; template: boolean }
const includePattern = /\{\{Incluir:([^{}]+)\}\}/gi
const includesIn = (source: string): Include[] => Array.from(source.matchAll(includePattern), match => {
  const [name, section = ''] = match[1].trim().split(/#(.*)/s)
  return { name: name.trim(), section }
})

/** Expande inclusões públicas e limita ciclos e expansão total antes da renderização. */
async function expand(db: D1Database, source: string, stack: string[], budget: { count: number }): Promise<string> {
  const parts = source.split(includePattern)
  if (parts.length === 1) return source
  if (stack.length > 8) throw new WikiError('include_depth', 'Limite de páginas incluídas excedido.')
  let html = parts[0]
  for (let i = 1; i < parts.length; i += 2) {
    const [name, section = ''] = parts[i].trim().split(/#(.*)/s)
    const key = identifier(name)
    if (stack.includes(key)) throw new WikiError('include_cycle', 'Inclusão circular: ' + name)
    if (++budget.count > 30) throw new WikiError('include_depth', 'Limite de páginas incluídas excedido.')
    const row = await query(db, `SELECT source FROM pages WHERE is_searchable = 1 AND legacy_identifier NOT LIKE 'page@%'
      AND (identifier = ? OR legacy_identifier = ?) ORDER BY identifier = ? DESC LIMIT 1`, key, legacyIdentifier(name), key)
      .first<{ source: string | null }>()
    let selected = row?.source ?? ''
    if (section === '!') selected = selected.split('==')[0]
    else if (section) selected = (selected.split('==' + section + '==')[1] ?? '').split('==')[0]
    html += (row ? await expand(db, selected, [...stack, key], budget)
      : '<strong>Página incluída não encontrada: ' + escape(name) + '</strong>') + parts[i + 1]
    if (html.length > 1_000_000) throw new WikiError('invalid_page', 'Conteúdo expandido muito grande.')
  }
  return html
}

/** Converte as listas simples e mantém tabelas HTML existentes sob a lista de tags permitidas. */
function lists(html: string) {
  return html.replace(/(?:^|\n)((?:[*#][^\n]*(?:\n|$))+)/g, (_all, block: string) => {
    let output = '\n'
    let current = ''
    for (const line of block.trimEnd().split('\n')) {
      const type = line[0] === '#' ? 'ol' : 'ul'
      if (type !== current) {
        if (current) output += '</' + current + '>'
        output += '<' + type + '>'
        current = type
      }
      output += '<li>' + line.slice(1).trim() + '</li>'
    }
    return output + '</' + current + '>\n'
  })
}

export async function renderWiki(db: D1Database, source: string, slug: string, frontend: string) {
  const normalized = source.replace(/\r\n?/g, '\n')
  let html = await expand(db, normalized, [identifier(slug)], { count: 0 })
  const categories = Array.from(new Set(Array.from(html.matchAll(/\{\{Categorias?:([^{}]+)\}\}/gi),
    match => match[1].split(',').map(name => name.replaceAll('_', ' ').trim())).flat().filter(Boolean)))
  const redirect = /\{\{\{Redirecionar:([^{}]+)\}\}\}/i.exec(html)?.[1].trim() ?? null
  const templates = Array.from(html.matchAll(/\{\{([^{}]+)\}\}/g), match => match[1])
  // Preserva artigos que ainda exigem macros do renderizador PHP.
  if (/^\s*\{\|/m.test(html) || templates.some(value =>
    !/^(?:Categorias?:|Imagem:|Video:|\/?Imagens$|Redirecionar:)/i.test(value))) {
    throw new WikiError('unsupported_markup', 'Este artigo contém marcação wiki ainda não migrada.')
  }
  const images: ImageRef[] = []
  const headings: { title: string; key: string; level: number }[] = []
  const links: Link[] = []
  const external: { url: string; label: string }[] = []
  const videos: string[] = []
  html = html.replace(/\{\{Categorias?:[^{}]+\}\}|\{\{\{Redirecionar:[^{}]+\}\}\}|\/\*[\s\S]*?\*\//gi, '')
  const parts = html.split(/\{\{Imagem:([^{}]+)\}\}/gi)
  if (parts.length > 51) throw new WikiError('invalid_page', 'Limite de imagens por edição excedido.')
  for (let i = 1; i < parts.length; i += 2) {
    const reference = parts[i].split('|')[0].trim()
    const name = reference.replace(/!tmpl$/i, '').trim()
    const key = identifier(name)
    const image = await query(db, `SELECT id, slug FROM images
      WHERE (identifier = ? OR legacy_identifier = ?) AND access_level_id <= 1
      ORDER BY identifier = ? DESC LIMIT 1`, key, 'imagem@' + legacyIdentifier(name), key).first<{ id: number; slug: string }>()
    images.push({ identifier: key, legacy: 'imagem@' + legacyIdentifier(name), template: /!tmpl$/i.test(reference) })
    const url = '{{{url_diretorio}}}' + articleImagePath(image?.slug ?? name).slice('/media/'.length)
    parts[i] = '<a href="' + frontend + '/i/' + encodeURIComponent(image?.slug ?? name) + '"><img src="' + escape(url) +
      '" alt="' + escape(name) + '"></a>'
  }
  html = parts.join('')
    .replace(/(={2,6})([^=\n]+)\1/g, (_all, marker: string, value: string) => {
      const title = strip(value)
      const key = legacyIdentifier(title)
      headings.push({ title, key, level: marker.length })
      return '<h' + marker.length + ' id="' + escape(key) + '">' + escape(title) + '</h' + marker.length + '>'
    })
    .replace(/'''([\s\S]*?)'''/g, '<strong>$1</strong>')
    .replace(/''([\s\S]*?)''/g, '<em>$1</em>')
    .replace(/\[codigo\]([\s\S]*?)\[\/codigo\]/gi, (_all, code: string) => '<pre><code>' + escape(code) + '</code></pre>')
    .replace(/\[\[([^\]]+)\]\]/g, (_all, inner: string) => {
      const [target, text] = inner.split(/\|(.*)/s)
      const label = strip(text || target)
      const href = target.trim()
      if (/^https?:\/\//i.test(href)) {
        external.push({ url: href, label })
        return '<a href="' + escape(href) + '" target="_blank" rel="noopener noreferrer">' + escape(label) + '</a>'
      }
      const [name, fragment = ''] = href.split(/#(.*)/s)
      const image = /^(?:imagem@|\/?i\/)(.+)$/i.exec(name)
      if (image) return '<a href="' + frontend + '/i/' + encodeURIComponent(image[1]) + '">' + escape(label) + '</a>'
      links.push({ name: name || slug, fragment, label })
      return '<a href="' + frontend + '/' + encodeURIComponent((name || slug).replaceAll(' ', '_')) + '/' +
        (fragment ? '#' + encodeURIComponent(legacyIdentifier(fragment)) : '') + '">' + escape(label) + '</a>'
    })
    .replace(/\{\{Video:([^|}]+)(?:\|[^}]*)?\}\}/gi, (_all, url: string) => {
      if (!/^https?:\/\//i.test(url)) return ''
      videos.push(url)
      return '<a href="' + escape(url) + '">' + escape(url) + '</a>'
    })
    .replace(/\{\{Imagens\}\}/gi, '<div class="wiki-gallery">').replace(/\{\{\/Imagens\}\}/gi, '</div>')

  return {
    html: sanitizeHtml(lists(html), {
      allowedTags: [...sanitizeHtml.defaults.allowedTags, 'img', 'figure', 'figcaption'],
      allowedAttributes: { '*': ['id', 'class'], a: ['href', 'target', 'rel'], img: ['src', 'alt', 'width', 'height'],
        td: ['rowspan', 'colspan'], th: ['rowspan', 'colspan', 'scope'] },
      allowedSchemes: ['http', 'https', 'mailto'], allowProtocolRelative: false,
    }),
    text: strip(normalized.replace(/<style\b[^>]*>[\s\S]*?(?:<\/style\s*>|$)/gi, '')
      .replace(/\{\{\{[^{}]*\}\}\}|\{\{[^{}]*\}\}/g, '')
      .replace(/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g, (_all, href: string, label?: string) => label || href)),
    categories, links, external, headings, images, videos, templates, redirect, includes: includesIn(normalized),
  }
}
export type RenderedWiki = Awaited<ReturnType<typeof renderWiki>>
