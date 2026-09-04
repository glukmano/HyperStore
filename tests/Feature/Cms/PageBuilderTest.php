<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cms\BlockTypeRegistry;
use Modules\Cms\Contracts\BlockTypeRegistryInterface;
use Modules\Cms\DTOs\BlockTypeDefinition;
use Modules\Cms\Exceptions\HtmlBlockNotPermittedException;
use Modules\Cms\Services\PageBlockRenderer;
use Modules\Cms\Services\PageBuilderService;
use Tests\TestCase;

class PageBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'cms-tenant', 'name' => 'CMS Tenant', 'status' => 'active']);
        $this->author = User::create(['name' => 'Author', 'email' => 'author-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
    }

    public function test_a_page_can_be_created_with_a_localized_translation_and_blocks(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);
        $service->setTranslation($page, 'en', 'About Us', 'about-us');

        $service->addBlock($page, 'hero', ['heading' => 'Welcome'], $this->author);
        $service->addBlock($page, 'rich_text', ['html' => '<p>Hello</p>'], $this->author);

        $this->assertSame('About Us', $page->translation('en')->title);
        $this->assertSame(2, $page->blocks()->count());
    }

    public function test_blocks_are_ordered_by_position_and_reorderable(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $first = $service->addBlock($page, 'hero', [], $this->author);
        $second = $service->addBlock($page, 'rich_text', ['html' => 'x'], $this->author);

        $this->assertSame([$first->id, $second->id], $page->blocks()->pluck('id')->all());

        $service->reorderBlocks($page, [$second->id, $first->id]);

        $this->assertSame([$second->id, $first->id], $page->fresh()->blocks()->pluck('id')->all());
    }

    public function test_the_html_block_is_rejected_without_explicit_permission(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $this->expectException(HtmlBlockNotPermittedException::class);
        $service->addBlock($page, 'html', ['html' => '<script>alert(1)</script>'], $this->author, canUseHtmlBlock: false);
    }

    public function test_the_html_block_content_is_sanitized_on_save_even_when_permitted(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $block = $service->addBlock($page, 'html', ['html' => '<script>alert(1)</script><p>Safe</p>'], $this->author, canUseHtmlBlock: true);

        $this->assertStringNotContainsString('<script>', $block->config['html']);
        $this->assertStringContainsString('Safe', $block->config['html']);
    }

    public function test_rich_text_block_content_is_also_sanitized(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $block = $service->addBlock($page, 'rich_text', ['html' => '<img src=x onerror=alert(1)>'], $this->author);

        $this->assertStringNotContainsString('onerror', $block->config['html']);
    }

    public function test_a_block_type_with_no_longer_registered_plugin_renders_a_safe_placeholder(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        // Register a plugin-provided block type, place a block using it...
        app(BlockTypeRegistryInterface::class)->register(new BlockTypeDefinition(
            key: 'plugin_widget',
            label: 'Plugin Widget',
            configSchema: [],
            viewPath: 'cms.blocks.rich-text',
        ));
        $block = $service->addBlock($page, 'plugin_widget', [], $this->author);

        // ...then simulate the plugin being disabled: a FRESH registry (as a
        // new request cycle would rebuild) never re-registers it.
        $freshRegistry = new BlockTypeRegistry;
        $this->app->instance(BlockTypeRegistryInterface::class, $freshRegistry);
        $renderer = new PageBlockRenderer($freshRegistry);

        $view = $renderer->render($block->fresh());

        $this->assertStringContainsString('_unavailable', $view->name());
    }

    public function test_block_config_containing_blade_syntax_is_never_executed_only_rendered_as_literal_output(): void
    {
        // Hard forbidden-shortcut regression test (ADR-0137): config is
        // JSONB data passed as a plain variable into a fixed Blade template
        // — it must never be compiled/evaluated as Blade source itself.
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $block = $service->addBlock($page, 'hero', ['heading' => '{{ 7 * 7 }}'], $this->author);

        $renderer = app(PageBlockRenderer::class);
        $rendered = $renderer->render($block)->render();

        $this->assertStringNotContainsString('49', $rendered);
        $this->assertStringContainsString('{{ 7 * 7 }}', $rendered);
    }
}
