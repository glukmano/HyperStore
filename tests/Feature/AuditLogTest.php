<?php

declare(strict_types=1);

use App\Core\Audit\AuditManager;
use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

// ── Container binding ─────────────────────────────────────────────────────────

test('AuditManagerInterface is bound in the container', function () {
    expect(app(AuditManagerInterface::class))->toBeInstanceOf(AuditManager::class);
});

// ── Logging events ────────────────────────────────────────────────────────────

test('audit manager logs an event', function () {
    $manager = app(AuditManagerInterface::class);

    $manager->log('platform.booted');

    $activity = Activity::latest()->first();

    expect($activity)->not->toBeNull();
    expect($activity->event)->toBe('platform.booted');
});

test('audit manager logs event with properties', function () {
    $manager = app(AuditManagerInterface::class);

    $manager->log('config.updated', ['key' => 'locale', 'value' => 'ar']);

    $activity = Activity::latest()->first();

    expect($activity->properties->get('key'))->toBe('locale');
    expect($activity->properties->get('value'))->toBe('ar');
});

test('audit manager logs event with causer', function () {
    $manager = app(AuditManagerInterface::class);
    $user = User::factory()->create();

    $manager->log('user.login', [], null, $user);

    $activity = Activity::latest()->first();

    expect($activity->causer_id)->toBe($user->id);
    expect($activity->causer_type)->toBe(User::class);
});

test('audit manager logs event with subject', function () {
    $manager = app(AuditManagerInterface::class);
    $user = User::factory()->create();

    $manager->log('user.profile_updated', [], $user);

    $activity = Activity::latest()->first();

    expect($activity->subject_id)->toBe($user->id);
    expect($activity->subject_type)->toBe(User::class);
});

// ── Activity log table exists ─────────────────────────────────────────────────

test('activity_log table exists in the database', function () {
    expect(Schema::hasTable('activity_log'))->toBeTrue();
});
