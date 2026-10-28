import { Hono } from "hono";
import { pages } from "./routes/pages";
import { HttpError } from "./errors";
import type { AppEnv } from "./types";

const app = new Hono<AppEnv>();
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
  console.error(error);
  return c.json({ error: "Erro interno." }, 500);
});
export default app;
