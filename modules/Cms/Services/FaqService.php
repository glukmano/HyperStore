<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use Modules\Cms\Models\Faq;

final class FaqService
{
    public function create(int $tenantId, ?string $category = null, int $sortOrder = 0): Faq
    {
        return Faq::query()->create([
            'tenant_id' => $tenantId,
            'category' => $category,
            'sort_order' => $sortOrder,
            'is_published' => true,
        ]);
    }

    public function setTranslation(Faq $faq, string $locale, string $question, string $answer): void
    {
        $faq->translations()->updateOrCreate(['locale' => $locale], ['question' => $question, 'answer' => $answer]);
    }
}
