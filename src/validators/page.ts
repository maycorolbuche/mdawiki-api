import { slugify } from '../content';
import { fail } from '../errors';
type Row = Record<string, any>;
function object(value: unknown): value is Row {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}
export function validate(body: unknown): Row {
  if (!object(body)) fail(422, "Envie um objeto JSON.");
  const b = body as Row;
  const allowed = new Set(
    "title type content categories metadata old_slug summary status is_searchable is_sponsored cover_media_id change_summary".split(
      " ",
    ),
  );
  for (const key of Object.keys(b))
    if (!allowed.has(key)) fail(422, `Campo desconhecido: ${key}`);
  if (typeof b.title !== "string" || !b.title.trim() || b.title.length > 250)
    fail(422, "title deve ter entre 1 e 250 caracteres.");
  if (!slugify(b.title) || slugify(b.title).length > 250)
    fail(422, "O título não gera um slug válido.");
  for (const key of ["content", "summary", "change_summary"])
    if (key in b && b[key] !== null && typeof b[key] !== "string")
      fail(422, `${key} deve ser texto ou null.`);
  if (
    "type" in b &&
    (typeof b.type !== "string" ||
      !/^[a-z][a-z0-9_]{0,49}$/.test(b.type) ||
      b.type === "redirect")
  )
    fail(422, "type inválido; redirect é gerado pela renomeação.");
  if (
    "old_slug" in b &&
    (typeof b.old_slug !== "string" || !b.old_slug || b.old_slug.length > 250)
  )
    fail(422, "old_slug inválido.");
  if ("metadata" in b && (!object(b.metadata) || "redirect" in b.metadata))
    fail(422, "metadata deve ser objeto e não pode conter redirect.");
  if ("status" in b && !["draft", "published", "archived"].includes(b.status))
    fail(422, "status inválido.");
  for (const key of ["is_searchable", "is_sponsored"])
    if (key in b && typeof b[key] !== "boolean")
      fail(422, `${key} deve ser booleano.`);
  if (
    "cover_media_id" in b &&
    b.cover_media_id !== null &&
    (!Number.isSafeInteger(b.cover_media_id) || b.cover_media_id < 1)
  )
    fail(422, "cover_media_id inválido.");
  if (
    "categories" in b &&
    (!Array.isArray(b.categories) ||
      b.categories.length > 100 ||
      b.categories.some(
        (c: unknown) =>
          !(typeof c === "string" && c.trim() && c.length <= 250) &&
          !(Number.isSafeInteger(c) && Number(c) > 0),
      ))
  )
    fail(
      422,
      "categories aceita até 100 IDs existentes ou nomes de categorias.",
    );
  return b;
}
