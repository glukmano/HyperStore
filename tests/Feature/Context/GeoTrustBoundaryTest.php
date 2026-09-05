<?php

declare(strict_types=1);

namespace Tests\Feature\Context;

use App\Core\Context\Contracts\GeoProviderInterface;
use App\Core\Context\Services\NullGeoProvider;
use App\Core\Context\Services\TrustedHeaderGeoProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §11: a Geo header is only ever honored when the
 * request genuinely arrived through a configured trusted proxy/CDN IP —
 * a non-null config value alone must not be sufficient.
 */
class GeoTrustBoundaryTest extends TestCase
{
    public function test_default_binding_is_the_null_provider_when_unconfigured(): void
    {
        config(['platform.trusted_geo_proxies' => [], 'platform.geo_country_header' => null]);
        $this->app->forgetInstance(GeoProviderInterface::class);
        $this->refreshApplication();

        $this->assertInstanceOf(NullGeoProvider::class, app(GeoProviderInterface::class));
    }

    public function test_header_from_an_untrusted_source_is_completely_ignored(): void
    {
        config([
            'platform.trusted_geo_proxies' => ['203.0.113.5'],
            'platform.geo_country_header' => 'CF-IPCountry',
        ]);

        $provider = new TrustedHeaderGeoProvider;
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.9']);
        $request->headers->set('CF-IPCountry', 'CH');

        $this->assertNull($provider->resolveCountry($request));
    }

    public function test_header_through_a_configured_trusted_proxy_is_honored(): void
    {
        config([
            'platform.trusted_geo_proxies' => ['203.0.113.0/24'],
            'platform.geo_country_header' => 'CF-IPCountry',
        ]);

        $provider = new TrustedHeaderGeoProvider;
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->headers->set('CF-IPCountry', 'ch');

        $this->assertSame('CH', $provider->resolveCountry($request));
    }

    public function test_no_trusted_proxies_configured_means_the_header_is_never_consulted(): void
    {
        config(['platform.trusted_geo_proxies' => [], 'platform.geo_country_header' => 'CF-IPCountry']);

        $provider = new TrustedHeaderGeoProvider;
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->headers->set('CF-IPCountry', 'CH');

        $this->assertNull($provider->resolveCountry($request));
    }

    public function test_malformed_header_value_is_rejected(): void
    {
        config(['platform.trusted_geo_proxies' => ['203.0.113.0/24'], 'platform.geo_country_header' => 'CF-IPCountry']);

        $provider = new TrustedHeaderGeoProvider;
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->headers->set('CF-IPCountry', "CH'; DROP TABLE users;--");

        $this->assertNull($provider->resolveCountry($request));
    }

    public function test_geo_never_becomes_authorization_truth_it_is_country_inference_only(): void
    {
        // Contract-level guard: the interface's only method returns a
        // plain ISO country code or null — nothing resembling an
        // authorization/permission decision is exposed on this seam.
        $reflection = new \ReflectionClass(GeoProviderInterface::class);
        $methods = $reflection->getMethods();

        $this->assertCount(1, $methods);
        $this->assertSame('resolveCountry', $methods[0]->getName());
    }
}
