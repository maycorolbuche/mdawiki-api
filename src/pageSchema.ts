import { contentConfig } from "./config/content";
import { pageConfig, pageTypes } from "./config/page";

export async function getPageSchema() {
  const definition = {
    page: pageConfig,
    html: contentConfig,
    types: pageTypes,
  };
  const hash = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(JSON.stringify(definition)),
  );
  const version = [...new Uint8Array(hash)]
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
  return { version, ...definition };
}
