import { pageTypes } from "../config/page";
import { fail } from "../errors";

export function validateMetadata(
  type: string,
  metadata: Record<string, unknown>,
): void {
  const definition = pageTypes.find((item) => item.value === type);
  if (!definition) fail(422, "Tipo de página não permitido.");
  for (const key of Object.keys(metadata))
    if (!definition.fields.some((field) => field.key === key))
      fail(422, `metadata.${key} não é permitido para ${type}.`);
  for (const field of definition.fields) {
    const value = metadata[field.key];
    if (
      value === undefined ||
      value === null ||
      (value === "" && ["text", "select", "date"].includes(field.input))
    ) {
      if (field.required) fail(422, `metadata.${field.key} é obrigatório.`);
      continue;
    }
    let valid = true;
    switch (field.input) {
      case "text":
        valid =
          typeof value === "string" && value.length <= (field.maxLength ?? 250);
        break;
      case "select":
        valid =
          field.options?.some((option) => option.value === value) ?? false;
        break;
      case "boolean":
        valid = typeof value === "boolean";
        break;
      case "number":
        valid =
          Number.isSafeInteger(value) &&
          Number(value) >= (field.min ?? Number.MIN_SAFE_INTEGER) &&
          Number(value) <= (field.max ?? Number.MAX_SAFE_INTEGER);
        break;
      case "date": {
        valid =
          typeof value === "string" &&
          /^\d{4}-\d{2}-\d{2}$/.test(value) &&
          value.slice(0, 4) !== "0000";
        if (valid) {
          const date = new Date(`${value}T00:00:00Z`);
          valid =
            !Number.isNaN(date.valueOf()) &&
            date.toISOString().slice(0, 10) === value;
        }
        break;
      }
      case "tags":
        valid =
          Array.isArray(value) &&
          value.length <= 100 &&
          value.every(
            (item) =>
              typeof item === "string" &&
              item.trim() &&
              item.length <= (field.maxLength ?? 250),
          );
        break;
    }
    if (!valid) fail(422, `metadata.${field.key} inválido.`);
  }
  if (
    type === "person" &&
    metadata.birth_date &&
    metadata.death_date &&
    String(metadata.death_date) < String(metadata.birth_date)
  )
    fail(422, "Falecimento não pode ser anterior ao nascimento.");
}
