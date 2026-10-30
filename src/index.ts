import { errorDetails } from "./errorDiagnostics";
import { cors } from "hono/cors";
import { defaultOrigins } from "./config/http";
import { getPageSchema } from "./pageSchema";
import { Hono } from "hono";
import { pages } from "./routes/pages";
import { HttpError } from "./errors";
import type { AppEnv } from "./types";

const app = new Hono<AppEnv>();
app.use("*", async (c, next) => {
  const origins =
    c.env.CORS_ORIGINS?.split(",")
      .map((item) => item.trim())
      .filter(Boolean) ?? defaultOrigins;
  return cors({
    origin: (origin) => (origins.includes(origin) ? origin : undefined),
    allowMethods: ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    allowHeaders: ["Content-Type", "If-None-Match"],
    exposeHeaders: ["ETag", "Location"],
    maxAge: 600,
  })(c, next);
});
app.get("/page-schema", async (c) => {
  const data = await getPageSchema();
  const etag = `"${data.version}"`;
  c.header("ETag", etag);
  c.header("Cache-Control", "no-cache");
  if (c.req.header("If-None-Match") === etag) return c.body(null, 304);
  return c.json({ data });
});
app.get("/", (c) => c.json([]));
app.route("/pages", pages);
app.notFound((c) => c.json({ error: "Rota não encontrada." }, 404));
app.onError((error, c) => {
  if (error instanceof HttpError)
    return c.json({ error: error.message }, error.status as 400);
  if (error instanceof URIError) return c.json({ error: "URL inválida." }, 400);
  if (
    /UNIQUE constraint|FOREIGN KEY constraint|SQLITE_CONSTRAINT/.test(
      String(error),
    )
  )
    return c.json(
      {
        error:
          "Conflito de gravação ou referência. Recarregue e tente novamente.",
      },
      409,
    );
  const errorId = crypto.randomUUID();
  console.error({ error_id: errorId, error });
  c.header("Cache-Control", "no-store");
  return c.json({
    error: "Erro interno.",
    error_id: errorId,
    ...(c.env.DEBUG_ERRORS === "true" ? { details: errorDetails(error) } : {}),
  }, 500);
});
export default app;

