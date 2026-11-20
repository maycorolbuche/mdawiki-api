<?php

declare(strict_types=1);

// Destination foreign keys and their legacy lookup fields, in priority order.
return [
    'comments' => [
        'page_id' => ['page_slug'],
    ],
    'page_visit_events' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_daily_visits' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_rebuild_jobs' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_categories' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
        'category_page_id' => ['category_identifier', 'category_slug'],
    ],
    'page_media' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
        'image_page_id' => ['image_identifier', 'image_slug'],
    ],
    'page_includes' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
        'target_page_id' => ['target_identifier', 'target_slug'],
    ],
    'page_links' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
        'target_page_id' => ['target_identifier'],
    ],
    'page_external_links' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_redirects' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
        'target_page_id' => ['target_identifier', 'target_slug'],
    ],
    'page_headings' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'legacy_page_cache' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_templates' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
    'page_videos' => [
        'page_id' => ['legacy_page_identifier', 'page_slug'],
    ],
];
