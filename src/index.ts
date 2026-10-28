/** Estrutura inicial: os endpoints de negócio serão implementados depois. */
export default {
  async fetch(): Promise<Response> {
    return new Response(null, { status: 404 });
  },
} satisfies ExportedHandler<Env>;
