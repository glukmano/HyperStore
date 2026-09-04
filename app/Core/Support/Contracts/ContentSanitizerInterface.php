<?php

declare(strict_types=1);

namespace App\Core\Support\Contracts;

/**
 * Stable contract so application code never depends directly on
 * stevebauman/purify's facade — keeps the sanitization backend swappable,
 * matching the AuditManagerInterface precedent.
 */
interface ContentSanitizerInterface
{
    /**
     * Strips script tags, event handlers, and javascript: URLs while
     * allowing a safe subset of formatting HTML (CMS blog/page body).
     */
    public function sanitizeRichHtml(string $html): string;

    /**
     * Strips ALL HTML — for plain-text contexts (review body, Q&A body)
     * that never need rich formatting.
     */
    public function stripAllHtml(string $text): string;
}
