import { parseDocument } from 'htmlparser2';
import serialize from 'dom-serializer';
import { Element, Text, type ChildNode } from 'domhandler';
import { isAllowedUrl } from './config/content';

const urls = () => /https?:\/\/[^\s<>"\u0000-\u001f\u007f]+/gi;
function urlValue(text: string) { return text.replace(/[.,;:!?]+$/, ''); }
function textOf(node: ChildNode): string {
  return node.type === 'text' ? node.data : 'children' in node ? node.children.map(textOf).join('') : '';
}
/** Executada após sanitizar o HTML: texto HTTP(S) é sempre um link para si mesmo. */
export function normalizeLinks(html: string): string {
  const document = parseDocument(html);
  function visit(nodes: ChildNode[], literal = false): ChildNode[] {
    return nodes.flatMap((node): ChildNode[] => {
      if (node.type === 'text' && !literal) {
        const output: ChildNode[] = [];
        let offset = 0;
        for (const match of node.data.matchAll(urls())) {
          const href = urlValue(match[0]);
          if (!isAllowedUrl(href, 'href')) continue;
          output.push(new Text(node.data.slice(offset, match.index)));
          output.push(new Element('a', { href, target: '_blank', rel: 'noopener noreferrer' }, [new Text(href)]));
          offset = match.index! + href.length;
        }
        if (!offset) return [node];
        output.push(new Text(node.data.slice(offset)));
        return output;
      }
      if (node.type === 'tag') {
        if (node.name === 'a') {
          const text = textOf(node).trim();
          const matches = [...text.matchAll(urls())];
          if (matches.length) {
            const href = urlValue(matches[0][0]);
            if (matches.length === 1 && urlValue(text) === href && isAllowedUrl(href, 'href')) {
              node.attribs.href = href;
            } else return visit(node.children, literal);
          }
          const href = node.attribs.href || '';
          delete node.attribs.target; delete node.attribs.rel;
          if (/^https?:\/\//i.test(href) && isAllowedUrl(href, 'href')) {
            node.attribs.target = '_blank'; node.attribs.rel = 'noopener noreferrer';
          }
          return [node];
        }
        node.children = visit(node.children, literal || ['pre', 'code'].includes(node.name));
      }
      return [node];
    });
  }
  document.children = visit(document.children);
  return serialize(document);
}
