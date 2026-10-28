import { cleanContent, slugify } from "./content";
export class HttpError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
  }
}
export const fail = (status: number, message: string): never => {
  throw new HttpError(status, message);
};
export const json = (data: unknown, status = 200, headers = {}) =>
  Response.json(data, { status, headers });
type Row = Record<string, any>;
export async function bySlug(db: D1Database, slug: string) {
  return db
    .prepare(
      "SELECT *, (SELECT COALESCE(MAX(version),0) FROM page_revisions WHERE page_id=pages.id) AS revision_version FROM pages WHERE slug = ?",
    )
    .bind(slug)
    .first<Row>();
}
export async function detail(db: D1Database, row: Row) {
  const cats = await db
    .prepare(
      "SELECT c.* FROM categories c JOIN page_categories pc ON pc.category_id=c.id WHERE pc.page_id=? ORDER BY pc.position",
    )
    .bind(row.id)
    .all();
  return {
    ...row,
    metadata: JSON.parse(row.metadata),
    is_searchable: !!row.is_searchable,
    is_sponsored: !!row.is_sponsored,
    categories: cats.results,
  };
}
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
export async function save(db: D1Database, body: Row, routeSlug?: string) {
  if (routeSlug && body.old_slug && body.old_slug !== routeSlug)
    fail(422, "old_slug difere do endereço da rota.");
  const slug = slugify(body.title);
  const source = routeSlug || body.old_slug || slug;
  const existing = await bySlug(db, source);
  if ((routeSlug || body.old_slug) && !existing)
    fail(404, "Página original não encontrada.");
  if (existing?.type === "redirect")
    fail(409, "O slug é um redirecionamento. Use o endereço atual da página.");
  const occupied = slug !== source ? await bySlug(db, slug) : null;
  if (occupied) fail(409, "O novo slug já está ocupado.");
  const previous: Row = existing ? await detail(db, existing) : {};
  const data: Row = {
    type: "article",
    content: "",
    metadata: {},
    summary: null,
    status: "draft",
    is_searchable: true,
    is_sponsored: false,
    cover_media_id: null,
    ...previous,
    ...body,
    slug,
  };
  const clean = await cleanContent(data.content || "");
  Object.assign(data, clean);
  delete data.media;
  const media = clean.media;
  function scan(value: unknown) {
    if (Array.isArray(value)) {
      value.forEach(scan);
      return;
    }
    if (!object(value)) return;
    for (const [key, val] of Object.entries(value)) {
      if (key.endsWith("_media_id") && val !== null) {
        if (!Number.isSafeInteger(val) || val < 1)
          fail(422, `${key} inválido.`);
        media.add(val);
      } else scan(val);
    }
  }
  scan(data.metadata);
  if (data.cover_media_id) media.add(data.cover_media_id);
  for (const id of media)
    if (!(await db.prepare("SELECT id FROM media WHERE id=?").bind(id).first()))
      fail(422, `Mídia inexistente: ${id}`);
  const categories: { id?: number; name: string; slug: string }[] = [];
  const seen = new Set<string>();
  for (const cat of body.categories ??
    previous.categories?.map((c: Row) => c.id) ??
    []) {
    let c;
    if (typeof cat === "number") {
      const found = await db
        .prepare("SELECT * FROM categories WHERE id=?")
        .bind(cat)
        .first<Row>();
      if (!found) fail(422, `Categoria inexistente: ${cat}`);
      c = { id: cat, name: found!.name as string, slug: found!.slug as string };
    } else {
      const cs = slugify(cat);
      if (!cs || cs.length > 250) fail(422, "Nome de categoria inválido.");
      c = { name: cat, slug: cs };
    }
    if (!seen.has(c.slug)) {
      seen.add(c.slug);
      categories.push(c);
    }
  }
  const stmts: D1PreparedStatement[] = [];
  const q = (sql: string, ...values: any[]) => db.prepare(sql).bind(...values);
  const fields = [
    "type",
    "title",
    "slug",
    "content",
    "content_text",
    "summary",
    "metadata",
    "cover_media_id",
    "status",
    "is_searchable",
    "is_sponsored",
  ];
  const vals = fields.map((k) =>
    k === "metadata"
      ? JSON.stringify(data[k])
      : typeof data[k] === "boolean"
        ? Number(data[k])
        : data[k],
  );
  const version = existing?.revision_version ?? 0;
  const snapshot = JSON.stringify({
    ...data,
    categories: categories.map((c) => ({ name: c.name, slug: c.slug })),
  });
  if (existing) {
    // A unique revision version prevents concurrent stale writes; batch rolls back all statements.
    stmts.push(
      q(
        "INSERT INTO page_revisions(page_id,version,snapshot,change_summary) VALUES(?,?,?,?)",
        existing.id,
        Number(version) + 1,
        snapshot,
        body.change_summary ?? null,
      ),
    );
    stmts.push(
      q(
        `UPDATE pages SET ${fields.map((k) => `${k}=?`).join(",")},updated_at=CURRENT_TIMESTAMP,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END WHERE id=?`,
        ...vals,
        data.status,
        existing.id,
      ),
    );
    if (slug !== source)
      stmts.push(
        q(
          "INSERT INTO pages(type,title,slug,metadata,is_searchable,status) VALUES('redirect',?,?,?,0,'published')",
          existing.title,
          source,
          JSON.stringify({ redirect: existing.id }),
        ),
      );
  } else {
    stmts.push(
      q(
        `INSERT INTO pages(${fields.join(",")},published_at) VALUES(${fields.map(() => "?").join(",")},CASE WHEN ?='published' THEN CURRENT_TIMESTAMP ELSE NULL END)`,
        ...vals,
        data.status,
      ),
    );
    stmts.push(
      q(
        "INSERT INTO page_revisions(page_id,version,snapshot,change_summary) SELECT id,1,?,? FROM pages WHERE slug=?",
        snapshot,
        body.change_summary ?? null,
        slug,
      ),
    );
  }
  stmts.push(
    q(
      "DELETE FROM page_categories WHERE page_id=(SELECT id FROM pages WHERE slug=?)",
      slug,
    ),
  );
  for (const [position, c] of categories.entries()) {
    if (!c.id)
      stmts.push(
        q(
          "INSERT INTO categories(name,slug) VALUES(?,?) ON CONFLICT(slug) DO NOTHING",
          c.name,
          c.slug,
        ),
      );
    stmts.push(
      q(
        "INSERT INTO page_categories(page_id,category_id,position) SELECT p.id,c.id,? FROM pages p,categories c WHERE p.slug=? AND c.slug=?",
        position,
        slug,
        c.slug,
      ),
    );
  }
  stmts.push(
    q(
      "DELETE FROM page_media WHERE page_id=(SELECT id FROM pages WHERE slug=?)",
      slug,
    ),
  );
  for (const id of media)
    stmts.push(
      q(
        "INSERT INTO page_media(page_id,media_id) SELECT id,? FROM pages WHERE slug=?",
        id,
        slug,
      ),
    );
  await db.batch(stmts);
  return json(
    { data: await detail(db, (await bySlug(db, slug))!) },
    existing ? 200 : 201,
  );
}
