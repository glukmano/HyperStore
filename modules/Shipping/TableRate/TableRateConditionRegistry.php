<?php

declare(strict_types=1);

namespace Modules\Shipping\TableRate;

use InvalidArgumentException;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class TableRateConditionRegistry
{
    /**
     * @var array<string, callable(ShippingRateRule, array{total_items: int, total_weight_kg: numeric-string, subtotal_minor: int, request: ShippingRateRequest}): bool>
     */
    private array $evaluators = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(string $type, callable $evaluator): void
    {
        $this->evaluators[$type] = $evaluator;
    }

    /**
     * @param  array{total_items: int, total_weight_kg: numeric-string, subtotal_minor: int, request: ShippingRateRequest}  $context
     */
    public function evaluate(ShippingRateRule $rule, array $context): bool
    {
        $payload = $rule->conditions_payload ?? [];
        if (empty($payload)) {
            return true;
        }

        foreach ($payload as $condKey => $condVal) {
            if (! isset($this->evaluators[$condKey])) {
                throw new InvalidArgumentException("Unknown table-rate condition type [{$condKey}].");
            }

            $passes = ($this->evaluators[$condKey])($rule, $context);
            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    private function registerDefaults(): void
    {
        $this->evaluators['min_weight'] = function (ShippingRateRule $rule, array $ctx): bool {
            $min = (string) ($rule->conditions_payload['min_weight'] ?? '0');

            return is_numeric($min) && bccomp($ctx['total_weight_kg'], $min, 4) >= 0;
        };

        $this->evaluators['max_weight'] = function (ShippingRateRule $rule, array $ctx): bool {
            $max = (string) ($rule->conditions_payload['max_weight'] ?? '999999');

            return is_numeric($max) && bccomp($ctx['total_weight_kg'], $max, 4) <= 0;
        };

        $this->evaluators['min_subtotal'] = function (ShippingRateRule $rule, array $ctx): bool {
            $min = (int) ($rule->conditions_payload['min_subtotal'] ?? 0);

            return $ctx['subtotal_minor'] >= $min;
        };

        $this->evaluators['max_subtotal'] = function (ShippingRateRule $rule, array $ctx): bool {
            $max = (int) ($rule->conditions_payload['max_subtotal'] ?? PHP_INT_MAX);

            return $ctx['subtotal_minor'] <= $max;
        };

        $this->evaluators['min_quantity'] = function (ShippingRateRule $rule, array $ctx): bool {
            $min = (int) ($rule->conditions_payload['min_quantity'] ?? 0);

            return $ctx['total_items'] >= $min;
        };

        $this->evaluators['max_quantity'] = function (ShippingRateRule $rule, array $ctx): bool {
            $max = (int) ($rule->conditions_payload['max_quantity'] ?? PHP_INT_MAX);

            return $ctx['total_items'] <= $max;
        };

        $this->evaluators['shipping_class'] = function (ShippingRateRule $rule, array $ctx): bool {
            $targetClassId = (int) ($rule->conditions_payload['shipping_class'] ?? 0);
            foreach ($ctx['request']->lines as $line) {
                if (isset($line['shipping_class_id']) && (int) $line['shipping_class_id'] === $targetClassId) {
                    return true;
                }
            }

            return false;
        };
    }
}
