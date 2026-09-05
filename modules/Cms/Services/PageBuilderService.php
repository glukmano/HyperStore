<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use App\Core\Support\Contracts\ContentSanitizerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Cms\Exceptions\HtmlBlockNotPermittedException;
use Modules\Cms\Models\Page;
use Modules\Cms\Models\PageBlock;

final class PageBuilderService
{
    public function __construct(
        private readonly ContentSanitizerInterface $sanitizer,
        private readonly CmsSlugValidator $slugValidator,
    ) {}

    public function create(int $tenantId, User $author, string $template = 'default'): Page
    {
        return Page::query()->create([
            'tenant_id' => $tenantId,
            'status' => Page::STATUS_DRAFT,
            'template' => $template,
            'created_by_user_id' => $author->id,
            'updated_by_user_id' => $author->id,
        ]);
    }

    public function setTranslation(Page $page, string $locale, string $title, string $slug, ?string $metaTitle = null, ?string $metaDescription = null): void
    {
        $this->slugValidator->assertAllowed($slug);

        $page->translations()->updateOrCreate(
            ['locale' => $locale],
            ['title' => $title, 'slug' => $slug, 'meta_title' => $metaTitle, 'meta_description' => $metaDescription],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function addBlock(Page $page, string $blockType, array $config, User $editor, bool $canUseHtmlBlock = false): PageBlock
    {
        if ($blockType === 'html' && ! $canUseHtmlBlock) {
            throw new HtmlBlockNotPermittedException('You do not have permission to use the Custom HTML block.');
        }

        if (in_array($blockType, ['rich_text', 'html'], true) && isset($config['html'])) {
            $config['html'] = $this->sanitizer->sanitizeRichHtml((string) $config['html']);
        }

        $nextPosition = (int) $page->blocks()->max('position') + 1;

        return $page->blocks()->create([
            'block_type' => $blockType,
            'position' => $nextPosition,
            'config' => $config,
            'is_visible' => true,
        ]);
    }

    /**
     * @param  list<int>  $orderedBlockIds
     */
    public function reorderBlocks(Page $page, array $orderedBlockIds): void
    {
        DB::transaction(function () use ($page, $orderedBlockIds): void {
            foreach ($orderedBlockIds as $position => $blockId) {
                PageBlock::query()->where('page_id', $page->id)->where('id', $blockId)->update(['position' => $position]);
            }
        });
    }

    public function removeBlock(Page $page, int $blockId): void
    {
        PageBlock::query()->where('page_id', $page->id)->where('id', $blockId)->delete();
    }

    public function publish(Page $page, User $editor): Page
    {
        $page->status = Page::STATUS_PUBLISHED;
        $page->published_at ??= now();
        $page->updated_by_user_id = $editor->id;
        $page->save();

        return $page;
    }
}
