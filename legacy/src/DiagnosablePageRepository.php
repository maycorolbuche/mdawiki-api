<?php
declare(strict_types=1);

namespace MDAWiki;

interface DiagnosablePageRepository extends PageRepository
{
    /** Diagnostic flags only; never returns content or credentials. */
    public function diagnose(string $slug): array;
}
