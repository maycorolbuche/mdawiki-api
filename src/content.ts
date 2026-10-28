const tags = new Set(
  "p br h2 h3 h4 h5 h6 strong em b i u s blockquote pre code ul ol li table thead tbody tfoot tr th td a img hr figure figcaption sup sub".split(
    " ",
  ),
);
const attrs: Record<string, string[]> = {
  a: ["href", "title"],
  img: ["src", "alt", "title", "data-media-id"],
  th: ["colspan", "rowspan"],
  td: ["colspan", "rowspan"],
  ol: ["start"],
};
export function slugify(title: string): string {
  return title
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}
export async function cleanContent(html: string) {
  const media = new Set<number>();
  const content = await new HTMLRewriter()
    .on("*", {
      element(el) {
        if (
          [
            "script",
            "style",
            "iframe",
            "object",
            "svg",
            "math",
            "template",
          ].includes(el.tagName)
        ) {
          el.remove();
          return;
        }
        if (!tags.has(el.tagName)) {
          el.removeAndKeepContent();
          return;
        }
        for (const [key, value] of [...el.attributes]) {
          if (!(attrs[el.tagName] || []).includes(key)) {
            el.removeAttribute(key);
            continue;
          }
          if (key === "href" || key === "src") {
            const safe =
              !/[\u0000-\u0020\u007f]/.test(value) &&
              (/^https?:\/\//i.test(value) ||
                /^\/(?!\/)/.test(value) ||
                (key === "href" && /^(#|mailto:)/i.test(value)));
            if (!safe) el.removeAttribute(key);
          }
          if (key === "data-media-id") {
            if (/^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)))
              media.add(Number(value));
            else el.removeAttribute(key);
          }
        }
      },
    })
    .transform(new Response(html))
    .text();
  let text = "";
  await new HTMLRewriter()
    .on("*", {
      element() {
        text += " ";
      },
      text(chunk) {
        text += chunk.text;
      },
    })
    .transform(new Response(content))
    .text();
  return { content, content_text: text.replace(/\s+/g, " ").trim(), media };
}
