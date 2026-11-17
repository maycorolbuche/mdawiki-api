<?php
declare(strict_types=1);

namespace MDAWiki;

interface PageRepository
{
    /** @return list<array<string, mixed>> */
    public function list(int $limit, string $after): array;

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array;
}
