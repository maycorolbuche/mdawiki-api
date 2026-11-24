import { decodeHTML } from 'entities'

export const missingImagePath = '/media/assets/img_nao_encontrada.svg'
export const articleImagePath = (name: string) => '/media/image/' + encodeURIComponent(name)

/** Atualiza referências de mídia do wiki sem reprocessar a marcação importada. */
export function presentArticleImages(html: string, api: string, frontend: string) {
  const site = new URL(frontend)
  const localHosts = new Set([site.host, 'mda.wiki.br', 'www.mda.wiki.br'])
  const updated = html.replace(/(<a\b[^>]*\bhref\s*=\s*(["'])([^"']*)\2[^>]*>)([\s\S]*?)(<\/a\s*>)/gi,
    (anchor: string, start: string, _quote: string, href: string, body: string, end: string) => {
      if (!/<img\b/i.test(body)) return anchor
      let name: string
      try {
        const link = new URL(decodeHTML(href), site)
        if (!localHosts.has(link.host) || !['http:', 'https:'].includes(link.protocol)) return anchor
        const path = decodeURIComponent(link.pathname).replace(/^\/site\//i, '/')
        const match = /^\/(?:i\/|imagem@)([^/]+)\/?$/i.exec(path)
        if (!match) return anchor
        name = match[1]
      } catch {
        return anchor
      }
      return start + body.replace(/(<img\b[^>]*?\s+src\s*=\s*)(["'])([^"']*)\2/gi,
        (tag: string, prefix: string, _delimiter: string, raw: string) => {
          const source = decodeHTML(raw)
          if (!/(?:\/media\/(?:articles\/|@imgs_wiki|wiki(?:_mini|_cp)?\/)|\/favicon\.svg(?:\?|$)|img_nao_encontrada\.|^imagem@)/i.test(source)) return tag
          return prefix + '"' + api + articleImagePath(name) + '"'
        }) + end
    })

  return updated.replace(/(<img\b[^>]*?\s+src\s*=\s*)(["'])([^"']*\bimg_nao_encontrada\.(?:png|svg)(?:\?[^"']*)?)\2/gi,
    (_tag: string, prefix: string) => prefix + '"' + api + missingImagePath + '"')
}
