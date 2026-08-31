<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ── Role creation ─────────────────────────────────────────────────────────────

test('can create a role', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    expect($role->name)->toBe('admin');
    expect(Role::where('name', 'admin')->exists())->toBeTrue();
});

test('can create a permission', function () {
    $permission = Permission::create(['name' => 'products.view', 'guard_name' => 'web']);

    expect($permission->name)->toBe('products.view');
});

// ── Role-Permission assignment ─────────────────────────────────────────────────

test('can assign permission to role', function () {
    $role = Role::create(['name' => 'catalog-manager', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'catalog.edit', 'guard_name' => 'web']);

    $role->givePermissionTo($permission);

    expect($role->hasPermissionTo('catalog.edit'))->toBeTrue();
});

// ── User-Role assignment ───────────────────────────────────────────────────────

test('can assign role to user', function () {
    $user = User::factory()->create();
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $user->assignRole('editor');

    expect($user->hasRole('editor'))->toBeTrue();
});

test('can check user permission via role', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders.view', 'guard_name' => 'web']);
    $role->givePermissionTo('orders.view');
    $user->assignRole('store-manager');

    expect($user->can('orders.view'))->toBeTrue();
    expect($user->can('orders.delete'))->toBeFalse();
});

// ── Role isolation ─────────────────────────────────────────────────────────────

test('user without role has no special permissions', function () {
    $user = User::factory()->create();
    Permission::create(['name' => 'admin.access', 'guard_name' => 'web']);

    expect($user->can('admin.access'))->toBeFalse();
});

test('role syncing works correctly', function () {
    $user = User::factory()->create();
    Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $user->assignRole('viewer', 'editor');
    $user->syncRoles(['viewer']);

    expect($user->hasRole('viewer'))->toBeTrue();
    expect($user->hasRole('editor'))->toBeFalse();
});
