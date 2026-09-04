<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Support\Contracts\ContentSanitizerInterface;
use Stevebauman\Purify\Facades\Purify;

final class ContentSanitizer implements ContentSanitizerInterface
{
    public function sanitizeRichHtml(string $html): string
    {
        /** @var string $clean */
        $clean = Purify::clean($html);

        return $clean;
    }

    public function stripAllHtml(string $text): string
    {
        return trim(strip_tags($text));
    }
}
