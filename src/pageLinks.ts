import { parseDocument } from 'htmlparser2';
import type { ChildNode } from 'domhandler';
import { slugify } from './content';
export type PageLink = { position: number; href: string; linkText: string; isExternal: boolean; targetSlug: string | null };
function text(node: ChildNode): string {
  return node.type === 'text' ? node.data : 'children' in node ? node.children.map(text).join('') : '';
}
export function extractPageLinks(html: string): PageLink[] {
  const links: PageLink[] = [];
  function walk(nodes: ChildNode[]) {
    for (const node of nodes) {
      if (node.type === 'tag' && node.name === 'a' && node.attribs.href) {
        const href = node.attribs.href;
        const isExternal = /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(href);
        let targetSlug: string | null = null;
        if (!isExternal && href.startsWith('/')) {
          try {
            const path = decodeURIComponent(href.split(/[?#]/)[0].slice(1).replace(/\/$/, ''));
            if (!path.includes('/')) { const slug = slugify(path); if (slug.length >= 2) targetSlug = slug; }
          } catch { /* URL inválida permanece registrada sem destino inferido. */ }
        }
        links.push({ position: links.length, href: targetSlug ? "/" + targetSlug : href, linkText: text(node), isExternal, targetSlug });
      }
      if ('children' in node) walk(node.children);
    }
  }
  walk(parseDocument(html).children);
  return links;
}

