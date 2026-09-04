<?php

declare(strict_types=1);

namespace Modules\Seo\Contracts;

interface StructuredDataBuilderInterface
{
    public function supports(object $subject): bool;

    /**
     * @return array<string, mixed>
     */
    public function build(object $subject): array;
}
