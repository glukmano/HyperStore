<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View as ViewFacade;
use Modules\Cms\Contracts\BlockTypeRegistryInterface;
use Modules\Cms\Models\PageBlock;

/**
 * Server-rendered Blade only. `config` is schema-validated JSONB data passed
 * into a fixed Blade include — never PHP-eval'd, never compiled as a
 * runtime Blade string from user input (ADR-0137's hard forbidden
 * shortcut). A block type that no longer exists in the registry (e.g. the
 * plugin that provided it was disabled) degrades to a safe placeholder,
 * never an error.
 */
final class PageBlockRenderer
{
    public function __construct(
        private readonly BlockTypeRegistryInterface $registry,
    ) {}

    public function render(PageBlock $block): View
    {
        $definition = $this->registry->get($block->block_type);

        if ($definition === null) {
            return ViewFacade::make('cms.blocks._unavailable', ['blockType' => $block->block_type]);
        }

        $config = $block->config ?? [];

        if ($definition->configSchema !== []) {
            $validator = Validator::make($config, $definition->configSchema);
            if ($validator->fails()) {
                return ViewFacade::make('cms.blocks._unavailable', ['blockType' => $block->block_type]);
            }
        }

        return ViewFacade::make($definition->viewPath, ['config' => $config]);
    }
}
