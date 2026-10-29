import { contentConfig, isAllowedUrl } from "./config/content";
const tags = new Set(contentConfig.tags);
const attrs = contentConfig.attributes;
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
        if (contentConfig.dropContents.includes(el.tagName)) {
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
            if (!isAllowedUrl(value, key)) el.removeAttribute(key);
          }
          if (key === "data-media-id") {
            if (/^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)))
              media.add(Number(value));
            else el.removeAttribute(key);
          }
        }
        if (el.tagName === "a") {
          const href = el.getAttribute("href") || "";
          el.removeAttribute("target"); el.removeAttribute("rel");
          if (/^https?:\/\//i.test(href) && isAllowedUrl(href, "href")) {
            el.setAttribute("target", "_blank");
            el.setAttribute("rel", "noopener noreferrer");
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

