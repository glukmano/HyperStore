<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Catalog\Models\Product;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ShippingArchitectureAndCapabilityTest extends TestCase
{
    public function test_shipping_and_fulfillment_modules_contain_no_hardcoded_product_type_string_comparisons(): void
    {
        $directories = [
            base_path('modules/Shipping'),
            base_path('modules/Fulfillment'),
        ];

        $disallowedPatterns = [
            "product_type === 'digital'",
            'product_type === "digital"',
            "product_type !== 'digital'",
            'product_type !== "digital"',
            "product_type === 'service'",
            'product_type === "service"',
            "product_type !== 'service'",
            'product_type !== "service"',
            "product_type === 'physical'",
            'product_type === "physical"',
            'switch ($product->product_type)',
            'switch ($product_type)',
        ];

        foreach ($directories as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    foreach ($disallowedPatterns as $pattern) {
                        $this->assertStringNotContainsString(
                            $pattern,
                            $content,
                            "File [{$file->getPathname()}] violates architecture rule by hardcoding product type string check [{$pattern}]."
                        );
                    }
                }
            }
        }
    }

    public function test_no_provider_order_array_selection_rates_0_in_shipping_calculators(): void
    {
        $calcFile = file_get_contents(base_path('modules/Shipping/Calculators/CarrierCalculatedRateCalculator.php'));
        $this->assertStringNotContainsString('$rates[0]', $calcFile);
        $this->assertStringNotContainsString('$rates[1]', $calcFile);
    }

    public function test_no_binary_float_arithmetic_in_carrier_markup_calculation(): void
    {
        $calcFile = file_get_contents(base_path('modules/Shipping/Calculators/CarrierCalculatedRateCalculator.php'));
        $this->assertStringNotContainsString('(float)', $calcFile);
        $this->assertStringNotContainsString('100.0', $calcFile);
    }

    public function test_shipping_rate_engine_contains_no_array_promotion_sniffing(): void
    {
        $engineFile = file_get_contents(base_path('modules/Shipping/Services/ShippingRateEngine.php'));
        $this->assertStringNotContainsString('is_array($benefit)', $engineFile);
        $this->assertStringNotContainsString("['type' => 'free_shipping']", $engineFile);
        $this->assertStringNotContainsString('is_array($benefit)', $engineFile);
    }

    public function test_catalog_capability_resolver_derives_shippability_for_all_product_types(): void
    {
        /** @var ProductShippingCapabilityResolverInterface $resolver */
        $resolver = app(ProductShippingCapabilityResolverInterface::class);

        $physical = new Product(['product_type' => 'physical']);
        $digital = new Product(['product_type' => 'digital']);
        $service = new Product(['product_type' => 'service']);
        $bundle = new Product(['product_type' => 'bundle']);
        $pod = new Product(['product_type' => 'print-on-demand']);
        $madeToOrder = new Product(['product_type' => 'made-to-order']);
        $rental = new Product(['product_type' => 'rental']);
        $ticket = new Product(['product_type' => 'ticket']);

        $this->assertTrue($resolver->requiresPhysicalShipping($physical), 'Physical product must require shipping');
        $this->assertFalse($resolver->requiresPhysicalShipping($digital), 'Digital product must NOT require shipping');
        $this->assertFalse($resolver->requiresPhysicalShipping($service), 'Service product must NOT require shipping');
        $this->assertTrue($resolver->requiresPhysicalShipping($bundle), 'Bundle product capability resolved by Catalog');
        $this->assertTrue($resolver->requiresPhysicalShipping($pod), 'Print on demand requires shipping');
        $this->assertTrue($resolver->requiresPhysicalShipping($madeToOrder), 'Made to order requires shipping');
        $this->assertTrue($resolver->requiresPhysicalShipping($rental), 'Rental requires shipping');
        $this->assertFalse($resolver->requiresPhysicalShipping($ticket), 'Ticket does NOT require shipping');
    }
}
