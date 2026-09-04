<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Channels\Models\Channel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ChannelManager extends Component
{
    public string $name = '';

    public string $handle = '';

    public string $type = 'website';

    public bool $is_active = true;

    public function createChannel(): void
    {
        if (! auth()->user()?->can('channels.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        Channel::create([
            'name' => $validated['name'],
            'handle' => $validated['handle'],
            'type' => $validated['type'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        $this->reset(['name', 'handle']);
        session()->flash('success', 'Channel created successfully.');
    }

    public function render(): View
    {
        return view('livewire.control-center.channel-manager', [
            'channels' => Channel::orderByDesc('id')->get(),
        ])->layout('layouts.control-center', ['title' => 'Channels']);
    }
}
