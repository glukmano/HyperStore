<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use Modules\Marketplace\Exceptions\InvalidDomainNameException;
use Modules\Marketplace\ValueObjects\DomainName;
use PHPUnit\Framework\TestCase;

class DomainNameTest extends TestCase
{
    public function test_valid_domain_is_normalized(): void
    {
        $domain = DomainName::from('Shop.AcmeCorp.COM.');
        $this->assertSame('shop.acmecorp.com', $domain->value());
    }

    public function test_scheme_is_rejected(): void
    {
        $this->expectException(InvalidDomainNameException::class);
        DomainName::from('https://vendor.example.com');
    }

    public function test_path_is_rejected(): void
    {
        $this->expectException(InvalidDomainNameException::class);
        DomainName::from('vendor.example.com/store');
    }

    public function test_port_is_rejected(): void
    {
        $this->expectException(InvalidDomainNameException::class);
        DomainName::from('vendor.example.com:8080');
    }

    public function test_ip_address_is_rejected(): void
    {
        $this->expectException(InvalidDomainNameException::class);
        DomainName::from('192.168.1.1');
    }

    public function test_idn_domain_is_converted_to_punycode(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension not available');
        }

        $domain = DomainName::from('münchen-store.de');
        $this->assertStringStartsWith('xn--', $domain->value());
    }
}
