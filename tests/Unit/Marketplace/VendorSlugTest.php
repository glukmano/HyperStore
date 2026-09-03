<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use Modules\Marketplace\Exceptions\InvalidVendorSlugException;
use Modules\Marketplace\ValueObjects\VendorSlug;
use PHPUnit\Framework\TestCase;

class VendorSlugTest extends TestCase
{
    public function test_valid_slug_is_normalized_to_lowercase(): void
    {
        $slug = VendorSlug::from('Acme-Corp');
        $this->assertSame('acme-corp', $slug->value());
        $this->assertSame('acme-corp', (string) $slug);
    }

    public function test_slug_with_numbers_and_hyphens_is_accepted(): void
    {
        $slug = VendorSlug::from('store-123-tech');
        $this->assertSame('store-123-tech', $slug->value());
    }

    public function test_slug_under_three_chars_throws_exception(): void
    {
        $this->expectException(InvalidVendorSlugException::class);
        VendorSlug::from('ab');
    }

    public function test_slug_over_sixty_four_chars_throws_exception(): void
    {
        $this->expectException(InvalidVendorSlugException::class);
        VendorSlug::from(str_repeat('a', 65));
    }

    public function test_slug_with_consecutive_hyphens_throws_exception(): void
    {
        $this->expectException(InvalidVendorSlugException::class);
        VendorSlug::from('acme--corp');
    }

    public function test_slug_with_special_characters_throws_exception(): void
    {
        $this->expectException(InvalidVendorSlugException::class);
        VendorSlug::from('acme_corp!');
    }

    public function test_reserved_keywords_are_rejected(): void
    {
        $reserved = ['admin', 'api', 'app', 'checkout', 'cart', 'portal', 'root', 'marketplace', 'vendor', 'vendors', 'www'];

        foreach ($reserved as $word) {
            try {
                VendorSlug::from($word);
                $this->fail("Expected reserved slug '{$word}' to be rejected.");
            } catch (InvalidVendorSlugException $e) {
                $this->assertStringContainsString('reserved platform keyword', $e->getMessage());
            }
        }
    }
}
