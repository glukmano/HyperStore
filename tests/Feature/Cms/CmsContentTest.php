<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cms\Exceptions\InvalidRedirectException;
use Modules\Cms\Exceptions\ReservedSlugException;
use Modules\Cms\Models\Page;
use Modules\Cms\Services\BlogService;
use Modules\Cms\Services\FaqService;
use Modules\Cms\Services\MenuService;
use Modules\Cms\Services\PageBuilderService;
use Modules\Cms\Services\RedirectService;
use Tests\TestCase;

class CmsContentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'cms-content-tenant', 'name' => 'CMS Content Tenant', 'status' => 'active']);
        $this->author = User::create(['name' => 'Author', 'email' => 'cmsauthor-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
    }

    public function test_a_page_falls_back_to_the_default_locale_when_the_requested_locale_is_missing(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);
        $service->setTranslation($page, 'en', 'About', 'about');

        $translation = $page->translation('ar');

        $this->assertSame('About', $translation->title);
    }

    public function test_a_draft_page_is_not_considered_published(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $this->assertFalse($page->isPublished());

        $service->publish($page, $this->author);
        $this->assertTrue($page->isPublished());
    }

    public function test_a_reserved_slug_is_rejected_for_a_cms_page(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $this->author);

        $this->expectException(ReservedSlugException::class);
        $service->setTranslation($page, 'en', 'Search', 'search');
    }

    public function test_pages_are_scoped_to_their_own_tenant(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other-cms-tenant', 'name' => 'Other CMS Tenant', 'status' => 'active']);

        $service = app(PageBuilderService::class);
        $service->create($this->tenant->id, $this->author);
        $service->create($otherTenant->id, $this->author);

        $this->assertSame(1, Page::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, Page::query()->where('tenant_id', $otherTenant->id)->count());
    }

    public function test_blog_post_body_is_sanitized_on_save(): void
    {
        $service = app(BlogService::class);
        $post = $service->create($this->tenant->id, $this->author);
        $service->setTranslation($post, 'en', 'Hello', 'hello-world', '<script>alert(1)</script><p>Body</p>');

        $this->assertStringNotContainsString('<script>', $post->translation('en')->body);
    }

    public function test_faq_translations_can_be_set_per_locale(): void
    {
        $service = app(FaqService::class);
        $faq = $service->create($this->tenant->id);
        $service->setTranslation($faq, 'en', 'Do you ship internationally?', 'Yes, we do.');
        $service->setTranslation($faq, 'ar', 'هل تشحنون دوليا؟', 'نعم');

        $this->assertSame('Yes, we do.', $faq->translation('en')->answer);
        $this->assertSame('نعم', $faq->translation('ar')->answer);
    }

    public function test_menu_items_support_a_parent_child_hierarchy(): void
    {
        $service = app(MenuService::class);
        $menu = $service->findOrCreate($this->tenant->id, 'header');

        $parent = $service->addItem($menu, 'page', '/about', 'About');
        $child = $service->addItem($menu, 'page', '/about/team', 'Team', parentId: $parent->id);

        $this->assertSame(1, $menu->items()->count());
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(1, $parent->children()->count());
    }

    public function test_a_redirect_loop_is_rejected(): void
    {
        $service = app(RedirectService::class);
        $service->create($this->tenant->id, '/old-page', '/new-page');

        $this->expectException(InvalidRedirectException::class);
        $service->create($this->tenant->id, '/new-page', '/old-page');
    }

    public function test_a_redirect_to_itself_is_rejected(): void
    {
        $service = app(RedirectService::class);

        $this->expectException(InvalidRedirectException::class);
        $service->create($this->tenant->id, '/same-page', '/same-page');
    }

    public function test_an_external_redirect_target_must_be_explicitly_flagged(): void
    {
        $service = app(RedirectService::class);

        $this->expectException(InvalidRedirectException::class);
        $service->create($this->tenant->id, '/external-attempt', 'https://evil.example.com', isExternal: false);
    }

    public function test_an_explicitly_flagged_external_redirect_is_permitted(): void
    {
        $service = app(RedirectService::class);
        $redirect = $service->create($this->tenant->id, '/partner-link', 'https://partner.example.com', isExternal: true);

        $this->assertTrue($redirect->is_external);
    }

    public function test_a_long_redirect_chain_beyond_the_depth_limit_is_rejected(): void
    {
        $service = app(RedirectService::class);
        $service->create($this->tenant->id, '/a', '/b');
        $service->create($this->tenant->id, '/b', '/c');
        $service->create($this->tenant->id, '/c', '/d');
        $service->create($this->tenant->id, '/d', '/e');
        $service->create($this->tenant->id, '/e', '/f');

        $this->expectException(InvalidRedirectException::class);
        $service->create($this->tenant->id, '/f', '/g');
    }
}
