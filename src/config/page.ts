/** Edite aqui os limites, tipos e campos publicados por GET /page-schema. */
export const pageConfig = {
  titleMinLength: 2,
  titleMaxLength: 250,
  slugMinLength: 2,
  slugMaxLength: 250,
  reservedSegmentLength: 1,
  defaultType: "article",
  maxCategories: 100,
};
export type MetadataField = {
  key: string;
  label: string;
  input: "text" | "number" | "date" | "select" | "boolean" | "tags";
  required?: boolean;
  min?: number;
  max?: number;
  maxLength?: number;
  options?: { value: string; label: string }[];
};
export type PageType = {
  value: string;
  label: string;
  fields: MetadataField[];
};
const icon: MetadataField = {
  key: "icon_media_id",
  label: "ID da imagem",
  input: "number",
  min: 1,
};
export const pageTypes: PageType[] = [
  { value: "article", label: "Artigo", fields: [icon] },
  {
    value: "specialty",
    label: "Especialidade",
    fields: [
      {
        key: "club_type",
        label: "Clube",
        input: "select",
        options: [
          { value: "pathfinders", label: "Desbravadores" },
          { value: "adventurers", label: "Aventureiros" },
        ],
      },
      { key: "area", label: "Área", input: "text", maxLength: 100 },
      { key: "code", label: "Código", input: "text", maxLength: 50 },
      { key: "level", label: "Nível", input: "number", min: 1, max: 3 },
      {
        key: "introduction_year",
        label: "Ano de introdução",
        input: "number",
        min: 1,
        max: 9999,
      },
      {
        key: "origin",
        label: "Instituição de origem",
        input: "text",
        maxLength: 250,
      },
      { key: "is_obsolete", label: "Obsoleta", input: "boolean" },
      icon,
    ],
  },
  {
    value: "person",
    label: "Pessoa",
    fields: [
      { key: "birth_date", label: "Nascimento", input: "date" },
      { key: "death_date", label: "Falecimento", input: "date" },
      { key: "positions", label: "Cargos", input: "tags", maxLength: 250 },
      icon,
    ],
  },
  {
    value: "club",
    label: "Clube",
    fields: [
      {
        key: "club_type",
        label: "Tipo de clube",
        input: "select",
        options: [
          { value: "pathfinders", label: "Desbravadores" },
          { value: "adventurers", label: "Aventureiros" },
        ],
      },
      { key: "foundation_date", label: "Fundação", input: "date" },
      { key: "city", label: "Cidade", input: "text", maxLength: 250 },
      icon,
    ],
  },
];
