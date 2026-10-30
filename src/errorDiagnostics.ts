/** Expõe a causa final sem stack, SQL do wrapper Drizzle ou seus parâmetros. */
export function errorDetails(error: Error) {
  let cause: Error = error;
  const seen = new Set<Error>();
  while (cause.cause instanceof Error && !seen.has(cause.cause)) {
    seen.add(cause);
    cause = cause.cause;
  }
  const message = cause.message.startsWith('Failed query:')
    ? 'Falha na consulta ao banco. Consulte os logs pelo error_id.'
    : cause.message.split(/\n(?:params:|parameters:)/i)[0].slice(0, 1500);
  return { type: cause.name, message };
}
