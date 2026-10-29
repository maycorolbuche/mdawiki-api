/** Política pública do corpo dos artigos. H1 pertence exclusivamente ao título. */
export const contentConfig = {
  tags: "p br h2 h3 h4 h5 h6 strong em b i u s blockquote pre code ul ol li table thead tbody tfoot tr th td a img hr figure figcaption sup sub".split(
    " ",
  ),
  attributes: {
    a: ["href", "title"],
    img: ["src", "alt", "title", "data-media-id"],
    th: ["colspan", "rowspan"],
    td: ["colspan", "rowspan"],
    ol: ["start"],
  } as Record<string, string[]>,
  urls: {
    protocols: ["http:", "https:"],
    linkProtocols: ["mailto:"],
    rootRelative: true,
    anchors: true,
  },
  dropContents: [
    "script",
    "style",
    "iframe",
    "object",
    "svg",
    "math",
    "template",
  ],
};

export function isAllowedUrl(value: string, attribute: string): boolean {
  if (/[\u0000-\u0020\u007f]/.test(value)) return false;
  const match = value.match(/^([a-z][a-z0-9+.-]*:)/i);
  if (match) {
    const protocol = match[1].toLowerCase();
    return (
      (contentConfig.urls.protocols.includes(protocol) &&
        /^https?:\/\//i.test(value)) ||
      (attribute === "href" &&
        contentConfig.urls.linkProtocols.includes(protocol))
    );
  }
  return (
    (contentConfig.urls.rootRelative && /^\/(?!\/)/.test(value)) ||
    (attribute === "href" &&
      contentConfig.urls.anchors &&
      value.startsWith("#"))
  );
}
