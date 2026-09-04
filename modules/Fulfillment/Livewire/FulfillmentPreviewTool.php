<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class FulfillmentPreviewTool extends Component
{
    public function render(): View
    {
        return view('fulfillment::livewire.preview-tool')
            ->layout('layouts.control-center', ['title' => 'Fulfillment Plan Preview']);
    }
}
