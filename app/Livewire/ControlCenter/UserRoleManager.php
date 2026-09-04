<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class UserRoleManager extends Component
{
    public ?int $selectedUserId = null;

    /** @var array<int, string> */
    public array $selectedRoles = [];

    public function updatedSelectedUserId(): void
    {
        $this->loadRolesForSelectedUser();
    }

    public function saveRoles(): void
    {
        if (! auth()->user()?->can('roles.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->selectedUserId === null) {
            return;
        }

        $user = User::query()->findOrFail($this->selectedUserId);
        $user->syncRoles($this->selectedRoles);

        session()->flash('success', 'Roles updated for '.$user->name.'.');
        $this->loadRolesForSelectedUser();
    }

    private function loadRolesForSelectedUser(): void
    {
        if ($this->selectedUserId === null) {
            $this->selectedRoles = [];

            return;
        }

        $user = User::query()->find($this->selectedUserId);
        $this->selectedRoles = $user?->roles()->pluck('name')->all() ?? [];
    }

    public function render(): View
    {
        $users = User::query()->orderBy('name')->limit(100)->get();
        $roles = Role::query()->orderBy('name')->get();

        $selectedUser = $this->selectedUserId !== null
            ? User::query()->find($this->selectedUserId)
            : null;

        $currentPermissions = $selectedUser?->getAllPermissions()->pluck('name')->all() ?? [];

        return view('livewire.control-center.user-role-manager', [
            'users' => $users,
            'roles' => $roles,
            'currentPermissions' => $currentPermissions,
        ])->layout('layouts.control-center', ['title' => 'Users & Roles']);
    }
}
