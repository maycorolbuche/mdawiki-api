<?php

declare(strict_types=1);

// Source table => [destination table, destination key, source-to-destination fields].
return [
    'COMENTARIOS' => ['comments', ['id'], [
        'ID' => 'id:number',
        'NOME_URL' => 'page_slug',
        'EMAIL' => 'author_email',
        'COMENTARIO' => 'body',
        'ID_PAI' => 'parent_id:number',
        'DATAHORA' => 'created_at:date',
    ]],
    'CONFIG' => ['settings', ['setting_key'], [
        'CHAVE' => 'setting_key',
        'VALOR' => 'setting_value',
    ]],
    'NIVEL' => ['access_levels', ['id'], [
        'NIVEL' => 'id:number',
        'DESCRICAO' => 'description',
    ]],
    'TAGS' => ['wiki_markup_rules', ['rule_key'], [
        'ID' => 'rule_key',
        'NOME' => 'name',
        'PARAM_CODE' => 'source_pattern',
        'PARAM_HTML' => 'html_pattern',
        'FUNCAO' => 'handler',
        'ORDEM' => 'position:number',
        'POSICAO' => 'scope',
    ]],
    'USUARIOS' => ['users', ['id'], [
        'ID' => 'id:number',
        'EMAIL' => 'email',
        'NOME' => 'name',
        'SENHA' => 'password_hash',
        'ATIVO' => 'is_active:flag',
        'APELIDO' => 'display_name',
        'DATA_NASC' => 'birth_date:date',
        'CIDADE' => 'city',
        'ESTADO' => 'state_code',
        'CLUBE' => 'club',
        'ASSOCIACAO' => 'association',
        'TIPO_CLUBE' => 'club_type',
        'BIOGRAFIA' => 'biography',
        '_ATUALIZ_PG_RELAC' => 'needs_related_page_rebuild:flag',
        'ENVIA_EMAIL' => 'receives_notifications:flag',
        'FEZ_LOGIN' => 'has_logged_in:flag',
        'NIVEL' => 'access_level_id:number',
        'UUID' => 'uuid',
        'DT_ULT_CADASTRO' => 'last_created_page_at:date',
        'QTD_CADASTRADOS' => 'created_page_count:number',
        'DT_ULT_ALTERACAO' => 'last_edited_page_at:date',
        'QTD_ALTERADOS' => 'edited_page_count:number',
        'DT_ULT_EXCLUSAO' => 'last_deleted_page_at:date',
        'QTD_APAGADOS' => 'deleted_page_count:number',
        'DT_ULT_ACESSO' => 'last_seen_at:date',
        'QTD_ACESSOS' => 'visit_count:number',
        'NIVEL_ACESSO' => 'legacy_access_level:number',
        'DT_CADASTRO' => 'created_at:date',
        'DT_ALTERACAO' => 'updated_at:date',
    ]],
];
