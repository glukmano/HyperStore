<?php

declare(strict_types=1);

namespace Modules\Inventory\Registries;

class InventorySourceTypeRegistry
{
    /** @var array<string, array{code: string, label: string, is_external: bool}> */
    private array $types = [];

    public function __construct()
    {
        $this->register('warehouse', 'Physical Facility Stock', false);
        $this->register('vendor', 'Marketplace Vendor Stock', true);
        $this->register('supplier', 'Supplier API Feed', true);
        $this->register('3pl', 'Third-Party Logistics (3PL)', true);
        $this->register('dropship', 'Drop-shipping Partner', true);
        $this->register('virtual', 'Virtual / Unlimited Stock', false);
    }

    public function register(string $code, string $label, bool $isExternal = false): void
    {
        $this->types[$code] = [
            'code' => $code,
            'label' => $label,
            'is_external' => $isExternal,
        ];
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /**
     * @return array<string, array{code: string, label: string, is_external: bool}>
     */
    public function all(): array
    {
        return $this->types;
    }
}
