<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperCenter;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeveloperCenterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_developer_center_index(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get(route('control-center.platform.developer.docs.index'))
            ->assertOk()
            ->assertSee('Developer Center');
    }

    public function test_super_admin_can_view_a_real_document(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get(route('control-center.platform.developer.docs.show', ['section' => 'plugins', 'slug' => 'overview']))
            ->assertOk();
    }

    public function test_a_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('control-center.platform.developer.docs.index'))
            ->assertForbidden();
    }
}
