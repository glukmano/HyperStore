<?php

declare(strict_types=1);

namespace Modules\Booking\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Booking\Models\AvailabilityRule;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Models\BookingService;
use RuntimeException;

class BookingResourceManager extends Component
{
    public string $name = '';

    public int $capacity = 1;

    public ?int $linkServiceId = null;

    public ?int $ruleResourceId = null;

    public int $weekday = 1;

    public string $startTime = '09:00';

    public string $endTime = '17:00';

    public string $timezone = 'UTC';

    public function mount(): void
    {
        $this->assertCan('booking.view');
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function createResource(): void
    {
        $this->assertCan('booking.manage');

        $resource = BookingResource::create([
            'tenant_id' => $this->tenantId(),
            'name' => $this->name,
            'capacity' => $this->capacity,
        ]);

        if ($this->linkServiceId !== null) {
            $resource->eligibleServices()->attach($this->linkServiceId);
        }

        $this->reset(['name', 'capacity', 'linkServiceId']);
        session()->flash('success', 'Resource created.');
    }

    public function addAvailabilityRule(): void
    {
        $this->assertCan('booking.manage');

        AvailabilityRule::create([
            'tenant_id' => $this->tenantId(),
            'booking_resource_id' => $this->ruleResourceId,
            'weekday' => $this->weekday,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'timezone' => $this->timezone,
        ]);

        session()->flash('success', 'Availability rule added.');
    }

    public function render(): View
    {
        $tenantId = $this->tenantId();

        return view('booking::livewire.control-center.booking-resource-manager', [
            'resources' => BookingResource::where('tenant_id', $tenantId)->with('availabilityRules', 'eligibleServices')->get(),
            'services' => BookingService::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Booking Resources']);
    }
}
