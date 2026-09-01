<?php

declare(strict_types=1);

namespace Modules\Shipping\TableRate;

use InvalidArgumentException;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Models\ShippingRateRule;

class TableRateActionRegistry
{
    /**
     * @var array<string, callable(ShippingRateRule, string, array{total_items: numeric-string, total_weight_kg: numeric-string, package_count: int}): MoneyValue>
     */
    private array $handlers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * @param  array{total_items: numeric-string, total_weight_kg: numeric-string, package_count: int}  $context
     */
    public function calculate(ShippingRateRule $rule, string $currency, array $context): MoneyValue
    {
        $payload = $rule->action_payload ?? [];
        $actionType = $rule->action_type ?? 'fixed_amount';

        if (! isset($this->handlers[$actionType])) {
            throw new InvalidArgumentException("Unknown table-rate action type [{$actionType}].");
        }

        return ($this->handlers[$actionType])($rule, $currency, $context);
    }

    private function registerDefaults(): void
    {
        $this->handlers['fixed_amount'] = function (ShippingRateRule $rule, string $curr, array $ctx): MoneyValue {
            $minor = (int) ($rule->action_payload['amount'] ?? 0);

            return MoneyValue::fromMinor($minor, $curr);
        };

        $this->handlers['per_item'] = function (ShippingRateRule $rule, string $curr, array $ctx): MoneyValue {
            $perItem = (int) ($rule->action_payload['per_item'] ?? 0);
            $perItemUnit = MoneyValue::fromMinor($perItem, $curr);

            return $perItemUnit->multiply((string) $ctx['total_items']);
        };

        $this->handlers['per_weight_step'] = function (ShippingRateRule $rule, string $curr, array $ctx): MoneyValue {
            $stepMinor = (int) ($rule->action_payload['step_amount'] ?? 0);
            $rawStep = (string) ($rule->action_payload['step_kg'] ?? '1.0000');
            /** @var numeric-string $stepSizeKg */
            $stepSizeKg = is_numeric($rawStep) ? $rawStep : '1.0000';
            if (bccomp($stepSizeKg, '0', 4) <= 0) {
                return MoneyValue::fromMinor(0, $curr);
            }
            $steps = (int) ceil((float) bcdiv($ctx['total_weight_kg'], $stepSizeKg, 4));

            return MoneyValue::fromMinor($stepMinor * $steps, $curr);
        };

        $this->handlers['per_package'] = function (ShippingRateRule $rule, string $curr, array $ctx): MoneyValue {
            $perPkg = (int) ($rule->action_payload['per_package'] ?? 0);

            return MoneyValue::fromMinor($perPkg * max(1, $ctx['package_count']), $curr);
        };
    }
}
