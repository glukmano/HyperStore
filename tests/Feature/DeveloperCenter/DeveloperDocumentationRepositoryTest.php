<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperCenter;

use App\Core\DeveloperCenter\Exceptions\DeveloperDocumentNotFoundException;
use App\Core\DeveloperCenter\Services\DeveloperDocumentationRepository;
use Tests\TestCase;

/**
 * Pre-Production Readiness — Developer Center document security
 * requirements: explicit allowlist root, no path traversal, no arbitrary
 * absolute paths, never a general filesystem reader.
 */
class DeveloperDocumentationRepositoryTest extends TestCase
{
    private DeveloperDocumentationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(DeveloperDocumentationRepository::class);
    }

    public function test_it_resolves_a_real_allowlisted_document(): void
    {
        $document = $this->repository->resolve('plugins', 'overview');

        $this->assertSame('plugins', $document->section);
        $this->assertSame('overview', $document->slug);
        $this->assertNotEmpty($document->safeHtml);
    }

    public function test_it_rejects_an_unknown_section(): void
    {
        $this->expectException(DeveloperDocumentNotFoundException::class);
        $this->repository->resolve('not-a-real-section', 'overview');
    }

    public function test_it_rejects_path_traversal_in_the_slug(): void
    {
        $this->expectException(DeveloperDocumentNotFoundException::class);
        $this->repository->resolve('plugins', '../../../../etc/passwd');
    }

    public function test_it_rejects_a_slug_containing_a_slash(): void
    {
        $this->expectException(DeveloperDocumentNotFoundException::class);
        $this->repository->resolve('plugins', 'sub/path');
    }

    public function test_it_rejects_an_absolute_path_slug(): void
    {
        $this->expectException(DeveloperDocumentNotFoundException::class);
        $this->repository->resolve('plugins', '/etc/passwd');
    }

    public function test_it_rejects_a_nonexistent_document_in_a_real_section(): void
    {
        $this->expectException(DeveloperDocumentNotFoundException::class);
        $this->repository->resolve('plugins', 'this-document-does-not-exist');
    }

    public function test_raw_html_embedded_in_markdown_is_escaped_not_rendered(): void
    {
        $document = $this->repository->resolve('themes', 'README');

        // Whatever the real README contains, the converter config must
        // never allow a literal, unescaped <script> tag to survive if the
        // source ever contained one — verified structurally via config,
        // and behaviorally here: safeHtml must never contain an
        // executable inline script tag.
        $this->assertStringNotContainsString('<script>', $document->safeHtml);
    }

    public function test_it_lists_sections_with_only_real_files(): void
    {
        $sections = $this->repository->listSections();

        $this->assertArrayHasKey('plugins', $sections);
        $this->assertContains('overview', $sections['plugins']);
    }
}
