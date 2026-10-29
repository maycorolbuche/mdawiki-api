import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { Miniflare, convertV4MiniflareOptions } from "miniflare";
import { build } from "esbuild";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
const state = mkdtempSync(join(tmpdir(), "mdawiki-test-"));
const base = "http://127.0.0.1:8791";
let mf;
try {
  const bundle = await build({
    entryPoints: ["src/index.ts"],
    bundle: true,
    format: "esm",
    write: false,
    target: "es2022",
  });
  mf = new Miniflare(
    convertV4MiniflareOptions({
      modules: true,
      script: bundle.outputFiles[0].text,
      compatibilityDate: "2026-10-27",
      d1Databases: { DB: "00000000-0000-0000-0000-000000000000" },
      resourcePersistencePath: state,
    }),
  );
  // Apply schema to the same D1 instance used by the Worker, avoiding
  // Wrangler/Miniflare persistence layout mismatches.
  const db = await mf.getD1Database("DB");
  const migrations = readdirSync("migrations")
    .filter((name) => name.endsWith(".sql"))
    .sort();
  assert.ok(migrations.length > 0, "Nenhuma migration SQL encontrada.");
  for (const filename of migrations) {
    const sql = readFileSync(join("migrations", filename), "utf8")
      .split(/\r?\n/)
      .filter((line) => !/^\s*--/.test(line))
      .join("\n")
      .trim();
    // A trigger contains semicolons inside BEGIN ... END; keep it intact.
    const statements = [];
    let buffer = "";
    let inTrigger = false;
    for (const line of sql.split(/\r?\n/)) {
      if (!line.trim()) continue;
      if (/^\s*CREATE\s+TRIGGER\b/i.test(line)) inTrigger = true;
      buffer += line.trim() + " ";
      if (inTrigger ? /^\s*END\s*;\s*$/i.test(line) : /;\s*$/.test(line)) {
        statements.push(buffer.trim().replace(/;\s*$/, ""));
        buffer = "";
        inTrigger = false;
      }
    }
    assert.equal(buffer.trim(), "", `Incomplete SQL in ${filename}: ${buffer}`);
    for (const [index, statement] of statements.entries()) {
      try {
        await db.prepare(statement).run();
      } catch (error) {
        throw new Error(
          `Migration ${filename}, statement ${index + 1} failed: ${statement}`,
          { cause: error },
        );
      }
    }
  }
  async function req(method, path, body, status = 200) {
    const r = await mf.dispatchFetch(base + path, {
      method,
      redirect: "manual",
      headers: { "Content-Type": "application/json" },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const raw = await r.text();
    assert.equal(r.status, status, raw);
    return raw ? JSON.parse(raw) : null;
  }
  const schemaResponse = await mf.dispatchFetch(base + "/page-schema", {
    headers: { Origin: "http://localhost:5006" },
  });
  assert.equal(
    schemaResponse.headers.get("Access-Control-Allow-Origin"),
    "http://localhost:5006",
  );
  const schema = (await schemaResponse.json()).data;
  assert.equal(schema.page.titleMinLength, 2);
  assert.ok(!schema.html.tags.includes("h1"));
  assert.ok(schema.html.tags.includes("h2") && schema.html.tags.includes("h6"));
  assert.ok(
    schema.types
      .find((type) => type.value === "person")
      .fields.some((field) => field.key === "birth_date"),
  );
  const cached = await mf.dispatchFetch(base + "/page-schema", {
    headers: { "If-None-Match": `"${schema.version}"` },
  });
  assert.equal(cached.status, 304);
  const preflight = await mf.dispatchFetch(base + "/pages", {
    method: "OPTIONS",
    headers: {
      Origin: "http://127.0.0.1:5006",
      "Access-Control-Request-Method": "POST",
      "Access-Control-Request-Headers": "Content-Type",
    },
  });
  assert.equal(
    preflight.headers.get("Access-Control-Allow-Origin"),
    "http://127.0.0.1:5006",
  );
  assert.ok(
    preflight.headers.get("Access-Control-Allow-Methods").includes("POST"),
  );
  const denied = await mf.dispatchFetch(base + "/page-schema", {
    headers: { Origin: "https://untrusted.example" },
  });
  assert.equal(denied.headers.get("Access-Control-Allow-Origin"), null);
  for (const title of ["A", " A ", "A!", "á!", "!!"])
    await req("POST", "/pages", { title }, 422);
  await req(
    "POST",
    "/pages",
    { title: "Ab", content: "<h1>Principal</h1><h2>Corpo</h2>" },
    201,
  );
  const ab = await req("GET", "/pages/ab");
  assert.ok(!ab.data.content.includes("<h1>"));
  await req("PUT", "/pages/ab", { title: "A!" }, 422);
  await req(
    "POST",
    "/pages",
    {
      title: "Pessoa teste",
      type: "person",
      metadata: { birth_date: "2024-02-30" },
    },
    422,
  );
  await req(
    "POST",
    "/pages",
    {
      title: "Pessoa teste",
      type: "person",
      metadata: { birth_date: "2024-02-29", death_date: "2020-01-01" },
    },
    422,
  );
  await req(
    "POST",
    "/pages",
    {
      title: "Pessoa teste",
      type: "person",
      metadata: {
        birth_date: "2000-02-29",
        death_date: null,
        positions: ["Diretor"],
      },
    },
    201,
  );
  await req(
    "PUT",
    "/pages/pessoa-teste",
    { title: "Pessoa teste", type: "specialty" },
    422,
  );
  await req("PUT", "/pages/pessoa-teste", {
    title: "Pessoa teste",
    type: "article",
    metadata: {},
  });
  await req("POST", "/pages", { title: "Tipo inválido", type: "unknown" }, 422);
  await req(
    "POST",
    "/pages",
    { title: "Campo inválido", type: "person", metadata: { area: "EN" } },
    422,
  );
  await req(
    "POST",
    "/pages",
    { title: "Campo inválido", type: "specialty", metadata: { level: "1" } },
    422,
  );
  await req(
    "POST",
    "/pages",
    {
      title: "Campo inválido",
      type: "specialty",
      metadata: { is_obsolete: "false" },
    },
    422,
  );
  await req(
    "POST",
    "/pages",
    {
      title: "Campo inválido",
      type: "specialty",
      metadata: { club_type: "invalid" },
    },
    422,
  );
  await req(
    "POST",
    "/pages",
    { title: "Campo inválido", type: "specialty", metadata: { level: 4 } },
    422,
  );
  const first = await req(
    "POST",
    "/pages",
    {
      title: "Especialidade dé Repteis",
      type: "specialty",
      content:
        '<h2>Requisitos</h2><p onclick="x()">Texto <strong>seguro</strong></p><script>alert(1)</script><img src="javascript:x">',
      categories: ["Natureza", "Répteis"],
      metadata: { club_type: "pathfinders", level: 1 },
    },
    201,
  );
  const id = first.data.id;
  assert.equal(first.data.slug, "especialidade-de-repteis");
  assert.ok(!/script|onclick|javascript:/.test(first.data.content));
  assert.ok(first.data.content_text.includes("Texto"));
  assert.equal(first.data.categories.length, 2);
  await req("POST", "/pages", { title: "especíalidade-De REPTEIS" }, 409);
  const same = await req("PUT", "/pages/especialidade-de-repteis", {
    title: "especíalidade-De REPTEIS",
  });
  assert.equal(same.data.id, id);
  assert.equal(same.data.title, "especíalidade-De REPTEIS");
  assert.equal(same.data.metadata.level, 1);
  const rename = await req("POST", "/pages", {
    title: "Répteis e Anfíbios",
    old_slug: "especialidade-de-repteis",
  });
  assert.equal(rename.data.id, id);
  const alias = await req(
    "GET",
    "/pages/especialidade-de-repteis",
    undefined,
    301,
  );
  assert.equal(alias.redirect, "repteis-e-anfibios");
  await req("PUT", "/pages/repteis-e-anfibios", {
    title: "Répteis atuais",
    categories: [],
    metadata: { level: 2 },
  });
  const alias2 = await req(
    "GET",
    "/pages/especialidade-de-repteis",
    undefined,
    301,
  );
  assert.equal(alias2.redirect, "repteis-atuais");
  await req("POST", "/pages", { title: "Outro" }, 201);
  await req(
    "POST",
    "/pages",
    { title: "Outro", old_slug: "repteis-atuais" },
    409,
  );
  await req("POST", "/pages", { title: "Especialidade de Repteis" }, 409);
  await req("POST", "/pages", { title: "Inválido", categories: [999999] }, 422);
  await req(
    "POST",
    "/pages",
    { title: "Inválido", metadata: { icon_media_id: 999999 } },
    422,
  );
  await req(
    "POST",
    "/pages",
    { title: "Inválido", metadata: { redirect: id } },
    422,
  );
  await req("POST", "/pages", { title: "Inválido", slug: "forcado" }, 422);
  await req("PUT", "/pages/ausente", { title: "Ausente" }, 404);
  const list = await req("GET", "/pages?type=specialty");
  assert.equal(list.data.length, 1);
  assert.equal(list.data[0].categories.length, 0);
  await req("GET", "/pages?limit=0", undefined, 422);
  const history = await db
    .prepare("SELECT COUNT(*) AS total FROM page_revisions WHERE page_id=?")
    .bind(id)
    .first();
  assert.equal(history.total, 4);
  await db
    .prepare(
      "INSERT INTO legacy_page_mappings(legacy_ident,legacy_url,page_id) VALUES(?,?,?)",
    )
    .bind("legacy", "legacy", id)
    .run();
  await req("DELETE", "/pages/repteis-atuais", undefined, 409);
  await req("GET", "/pages/especialidade-de-repteis", undefined, 301);
  await db.prepare("DELETE FROM legacy_page_mappings").run();
  await req("DELETE", "/pages/repteis-atuais", undefined, 204);
  await req("GET", "/pages/especialidade-de-repteis", undefined, 404);
  await req("GET", "/pages/repteis-e-anfibios", undefined, 404);
  await req("GET", "/pages/repteis-atuais", undefined, 404);
  console.log(
    "CRUD integration checks passed (real local Worker + D1, no authentication).",
  );
} finally {
  if (mf) await mf.dispose();
  rmSync(state, { recursive: true, force: true });
}
