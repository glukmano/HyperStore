<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Phase-17: password reset and email verification, both using Laravel's
 * standard Password/notification broker against App\Models\User and the
 * platform's own password_reset_tokens table — no second identity system.
 */
class PasswordResetAndEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'name' => 'Reset Test User',
            'email' => 'reset-test@hyperstore.test',
            'password' => bcrypt('old-password'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get(route('password.request'))->assertStatus(200);
    }

    public function test_requesting_a_reset_link_sends_the_notification(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => $this->user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($this->user, ResetPassword::class);
    }

    public function test_requesting_a_reset_link_for_an_unknown_email_does_not_reveal_existence(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

        $response->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password_and_allows_login(): void
    {
        $token = Password::createToken($this->user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
        ])->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $this->user->email,
            'password' => 'brand-new-password-123',
        ]);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $response = $this->post(route('password.store'), [
            'token' => 'not-a-real-token',
            'email' => $this->user->email,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_verification_prompt_redirects_an_already_verified_user(): void
    {
        $this->user->markEmailAsVerified();

        $this->actingAs($this->user)
            ->get(route('verification.notice'))
            ->assertRedirect(route('storefront.home'));
    }

    public function test_verification_prompt_shows_for_an_unverified_user(): void
    {
        $this->user->email_verified_at = null;
        $this->user->save();

        $this->actingAs($this->user)
            ->get(route('verification.notice'))
            ->assertStatus(200)
            ->assertSee('verify your email', false);
    }

    public function test_resending_verification_email_dispatches_the_notification(): void
    {
        Notification::fake();
        $this->user->email_verified_at = null;
        $this->user->save();

        $this->actingAs($this->user)
            ->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($this->user, VerifyEmail::class);
    }

    public function test_registering_a_new_customer_dispatches_a_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'New Customer',
            'email' => 'new-customer@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $user = User::where('email', 'new-customer@example.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_login_is_permitted_before_email_verification(): void
    {
        $this->user->email_verified_at = null;
        $this->user->save();

        $this->post(route('login.store'), [
            'email' => $this->user->email,
            'password' => 'old-password',
        ]);

        $this->assertAuthenticatedAs($this->user);
    }
}
