import { extractPageLinks } from "../pageLinks";
import { sql } from "drizzle-orm";
import { atomic, database } from "../db";
import { Page, type PageRecord, type PageInput } from "../models/Page";
import { pages } from "../db/schema";
import { cleanContent, slugify } from "../content";
import { validateMetadata } from "../validators/metadata";
import { pageConfig } from "../config/page";
import { fail } from "../errors";

type Input = Record<string, any>;
type CategoryInput = { id?: number; name: string; slug: string };

export class PageService {
  private readonly model: Page;
  constructor(private readonly binding: D1Database) {
    this.model = new Page(database(binding));
  }

  private async detail(row: PageRecord) {
    const {
      contentText,
      coverMediaId,
      isSearchable,
      isSponsored,
      createdBy,
      updatedBy,
      publishedAt,
      createdAt,
      updatedAt,
      ...publicFields
    } = row;
    return {
      ...publicFields,
      content_text: row.contentText,
      cover_media_id: row.coverMediaId,
      is_searchable: row.isSearchable,
      is_sponsored: row.isSponsored,
      created_by: row.createdBy,
      updated_by: row.updatedBy,
      published_at: row.publishedAt,
      created_at: row.createdAt,
      updated_at: row.updatedAt,
      categories: await this.model.categories(row.id),
    };
  }

  async list(limit: number, offset: number, type: string | null) {
    const rows = await this.model.list(limit, offset, type);
    return {
      data: await Promise.all(rows.map((row) => this.detail(row))),
      pagination: { limit, offset },
    };
  }

  async get(slug: string) {
    const row = await this.model.findBySlug(slug);
    if (!row) fail(404, "Página não encontrada.");
    if (row.type === "redirect") {
      const dest = await this.model.redirectTarget(
        Number(row.metadata.redirect),
      );
      if (!dest) fail(404, "Destino não encontrado.");
      return { redirect: dest.slug };
    }
    return { data: await this.detail(row) };
  }

  async save(body: Input, routeSlug?: string) {
    if (routeSlug && body.old_slug && body.old_slug !== routeSlug)
      fail(422, "old_slug difere do endereço da rota.");
    const slug = slugify(body.title);
    const source = routeSlug || body.old_slug || slug;
    const existing = await this.model.findBySlug(source);
    if (!routeSlug && !body.old_slug && existing)
      fail(409, "Já existe uma página neste endereço. Use a edição.");
    if ((routeSlug || body.old_slug) && !existing)
      fail(404, "Página original não encontrada.");
    if (existing?.type === "redirect")
      fail(
        409,
        "O slug é um redirecionamento. Use o endereço atual da página.",
      );
    if (slug !== source && (await this.model.findBySlug(slug)))
      fail(409, "O novo slug já está ocupado.");

    const previous: Input = existing ? await this.detail(existing) : {};
    const data: Input = {
      type: pageConfig.defaultType,
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
    validateMetadata(data.type, data.metadata);
    const clean = await cleanContent(data.content || "");
    Object.assign(data, clean);
    const mediaIds = clean.media;

    const scan = (value: unknown): void => {
      if (Array.isArray(value)) return void value.forEach(scan);
      if (!value || typeof value !== "object") return;
      for (const [key, item] of Object.entries(value)) {
        if (key.endsWith("_media_id") && item !== null) {
          if (!Number.isSafeInteger(item) || (item as number) < 1)
            fail(422, `${key} inválido.`);
          mediaIds.add(item as number);
        } else scan(item);
      }
    };
    scan(data.metadata);
    if (data.cover_media_id) mediaIds.add(data.cover_media_id);
    for (const id of mediaIds)
      if (!(await this.model.mediaExists(id)))
        fail(422, `Mídia inexistente: ${id}`);

    const selected: CategoryInput[] = [];
    const seen = new Set<string>();
    for (const cat of body.categories ??
      previous.categories?.map((c: Input) => c.id) ??
      []) {
      let item: CategoryInput;
      if (typeof cat === "number") {
        const found = await this.model.categoryById(cat);
        if (!found) fail(422, `Categoria inexistente: ${cat}`);
        item = { id: cat, name: found.name, slug: found.slug };
      } else {
        const catSlug = slugify(cat);
        if (!catSlug || catSlug.length > 250)
          fail(422, "Nome de categoria inválido.");
        item = { name: cat, slug: catSlug };
      }
      if (!seen.has(item.slug)) {
        seen.add(item.slug);
        selected.push(item);
      }
    }

    const values: PageInput = {
      type: data.type,
      title: data.title,
      slug,
      content: data.content,
      contentText: data.content_text,
      summary: data.summary,
      metadata: data.metadata,
      coverMediaId: data.cover_media_id,
      status: data.status,
      isSearchable: data.is_searchable,
      isSponsored: data.is_sponsored,
    };
    const snapshot = {
      ...data,
      media: undefined,
      categories: selected.map((c) => ({ name: c.name, slug: c.slug })),
    };
    const statements: Array<{ toSQL(): { sql: string; params: unknown[] } }> =
      [];
    if (existing) {
      const version = await this.model.latestVersion(existing.id);
      statements.push(
        this.model.addRevision(
          existing.id,
          version + 1,
          snapshot,
          body.change_summary ?? null,
        ),
      );
      statements.push(
        this.model.update(existing.id, {
          ...values,
          updatedAt: sql`CURRENT_TIMESTAMP` as unknown as string,
          publishedAt:
            data.status === "published"
              ? (sql`coalesce(${pages.publishedAt}, CURRENT_TIMESTAMP)` as unknown as string)
              : existing.publishedAt,
        }),
      );
      if (slug !== source) {
        statements.push(
          this.model.insert({
            type: "redirect",
            title: existing.title,
            slug: source,
            metadata: { redirect: existing.id },
            isSearchable: false,
            status: "published",
          }),
        );
      }
    } else {
      statements.push(
        this.model.insert({
          ...values,
          publishedAt:
            data.status === "published"
              ? (sql`CURRENT_TIMESTAMP` as unknown as string)
              : null,
        }),
      );
      statements.push(
        this.model.createRevisionForSlug(
          slug,
          snapshot,
          body.change_summary ?? null,
        ),
      );
    }
    statements.push(this.model.clearCategories(slug));
    for (const [position, cat] of selected.entries()) {
      if (!cat.id) statements.push(this.model.addCategory(cat.name, cat.slug));
      statements.push(this.model.attachCategory(slug, cat.slug, position));
    }
    statements.push(this.model.clearMedia(slug));
    for (const id of mediaIds)
      statements.push(this.model.attachMedia(slug, id));
    statements.push(this.model.clearLinks(slug));
    for (const link of extractPageLinks(data.content)) statements.push(this.model.attachLink(slug, link));
    await atomic(this.binding, statements);
    const saved = await this.model.findBySlug(slug);
    if (!saved) throw new Error("Página não encontrada após gravação.");
    return { data: await this.detail(saved), status: existing ? 200 : 201 };
  }

  async delete(slug: string) {
    const row = await this.model.findBySlug(slug);
    if (!row) fail(404, "Página não encontrada.");
    await atomic(this.binding, [
      this.model.removeRedirectsTo(row.id),
      this.model.deleteById(row.id),
    ]);
  }
}

