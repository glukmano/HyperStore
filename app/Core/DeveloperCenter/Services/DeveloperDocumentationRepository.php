<?php

declare(strict_types=1);

namespace App\Core\DeveloperCenter\Services;

use App\Core\DeveloperCenter\DTOs\DeveloperDocument;
use App\Core\DeveloperCenter\Exceptions\DeveloperDocumentNotFoundException;
use League\CommonMark\CommonMarkConverter;

/**
 * Pre-Production Readiness / Developer Center document security
 * requirements: this repository renders ONLY Markdown files that live
 * under an explicit, hardcoded root allowlist below `docs/` — never an
 * arbitrary filesystem reader.
 *
 * - `$section` must be one of the allowlisted keys below (no free-form path).
 * - `$slug` is validated against a strict single-segment pattern
 *   (letters/digits/hyphen/underscore only) — no `/`, no `..`, no absolute
 *   paths are even syntactically possible.
 * - The final resolved real path is additionally verified (via
 *   `realpath()`) to still live inside the allowlisted root directory,
 *   as defense-in-depth against any future loosening of the slug pattern.
 * - Raw HTML embedded in the Markdown source is escaped, never rendered
 *   (`html_input => 'escape'`), and unsafe link schemes are rejected
 *   (`allow_unsafe_links => false`) — this repository never trusts
 *   repository Markdown content to be safe-by-default.
 */
final class DeveloperDocumentationRepository
{
    /**
     * @var array<string, string>
     */
    private const ALLOWED_SECTIONS = [
        'root' => 'docs',
        'phases' => 'docs/phases',
        'modules' => 'docs/modules',
        'decisions' => 'docs/decisions',
        'plugins' => 'docs/plugins',
        'themes' => 'docs/themes',
        'api' => 'docs/api',
        'architecture' => 'docs/architecture',
        'operations' => 'docs/operations',
        'qa' => 'docs/qa',
    ];

    private readonly CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function listSections(): array
    {
        $result = [];
        foreach (self::ALLOWED_SECTIONS as $section => $relativeRoot) {
            $result[$section] = $this->listSlugs($section);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function listSlugs(string $section): array
    {
        $root = $this->allowlistedRoot($section);
        if (! is_dir($root)) {
            return [];
        }

        $slugs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if (str_ends_with($entry, '.md')) {
                $slugs[] = substr($entry, 0, -3);
            }
        }

        sort($slugs);

        return $slugs;
    }

    public function resolve(string $section, string $slug): DeveloperDocument
    {
        $root = $this->allowlistedRoot($section);

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            throw new DeveloperDocumentNotFoundException("Invalid document slug [{$slug}].");
        }

        $path = $root.DIRECTORY_SEPARATOR.$slug.'.md';
        $realPath = realpath($path);
        $realRoot = realpath($root);

        if ($realPath === false || $realRoot === false || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)) {
            throw new DeveloperDocumentNotFoundException("Document [{$section}/{$slug}] was not found.");
        }

        $markdown = (string) file_get_contents($realPath);
        $title = $this->extractTitle($markdown) ?? $slug;
        $safeHtml = (string) $this->converter->convert($markdown);

        return new DeveloperDocument($section, $slug, $title, $safeHtml);
    }

    private function allowlistedRoot(string $section): string
    {
        if (! isset(self::ALLOWED_SECTIONS[$section])) {
            throw new DeveloperDocumentNotFoundException("Unknown documentation section [{$section}].");
        }

        return base_path(self::ALLOWED_SECTIONS[$section]);
    }

    private function extractTitle(string $markdown): ?string
    {
        foreach (explode("\n", $markdown) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '# ')) {
                return trim(substr($trimmed, 2));
            }
        }

        return null;
    }
}
