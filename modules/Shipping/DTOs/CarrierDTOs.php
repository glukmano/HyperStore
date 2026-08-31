<?php

declare(strict_types=1);

namespace Modules\Shipping\DTOs;

use DateTimeImmutable;
use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class CarrierServiceDTO
{
    public function __construct(
        public string $carrierCode,
        public string $serviceCode,
        public string $serviceName,
        public bool $isActive = true,
        public int $transitDaysMin = 1,
        public int $transitDaysMax = 3
    ) {}
}

final readonly class TrackingEventDTO
{
    public function __construct(
        public string $status,
        public string $description,
        public string $location,
        public DateTimeImmutable $occurredAt
    ) {}
}

final readonly class TrackingResult
{
    /**
     * @param  array<int, TrackingEventDTO>  $events
     */
    public function __construct(
        public string $trackingNumber,
        public string $carrierCode,
        public string $status, // pre_transit, in_transit, out_for_delivery, delivered, failed, returned
        public ?DateTimeImmutable $estimatedDelivery = null,
        public array $events = []
    ) {}
}

final readonly class CreateLabelRequest
{
    /**
     * @param  array<int, mixed>  $packages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $carrierCode,
        public string $serviceCode,
        public string $shipmentReference,
        public array $packages = [],
        public array $metadata = []
    ) {}
}

final readonly class CreateLabelResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $trackingNumber,
        public string $labelUrl,
        public string $labelFormat, // pdf, png, zpl
        public MoneyValue $cost,
        public array $metadata = []
    ) {}
}

final readonly class CancelLabelResult
{
    public function __construct(
        public bool $isCancelled,
        public ?string $message = null
    ) {}
}
