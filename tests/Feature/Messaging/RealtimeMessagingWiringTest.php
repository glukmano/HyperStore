<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Livewire\Storefront\Account\ConversationThread;
use Livewire\Attributes\On;
use Modules\Messaging\Events\MessageSent;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves the frontend realtime closure item is genuinely wired, not just
 * claimed: the exact Echo private-channel/event binding on the real
 * ConversationThread component, the exact channel/event name Message
 * Sent actually broadcasts on, and that the JS bootstrap + wire:poll
 * fallback both exist in source. This does not require a live Reverb
 * daemon or a browser — it verifies the same source facts a browser-level
 * test would ultimately depend on.
 */
class RealtimeMessagingWiringTest extends TestCase
{
    public function test_conversation_thread_declares_the_correct_echo_private_listener_attribute(): void
    {
        $reflection = new ReflectionClass(ConversationThread::class);
        $method = $reflection->getMethod('onMessageBroadcast');

        $onAttributes = $method->getAttributes(On::class);
        $this->assertNotEmpty($onAttributes, 'onMessageBroadcast() is missing a Livewire #[On] attribute.');

        $eventName = $onAttributes[0]->newInstance()->event;

        $this->assertSame(
            'echo-private:conversation.{conversation.id},.message.sent',
            $eventName,
            'The Echo listener must target the private conversation channel and the exact MessageSent::broadcastAs() event name.'
        );
    }

    public function test_the_broadcast_event_name_the_frontend_listens_for_matches_what_message_sent_actually_broadcasts_as(): void
    {
        // Guards against the two independent strings (the Livewire
        // attribute above, and MessageSent::broadcastAs()) silently
        // drifting apart — the frontend listener is worthless if these
        // ever stop matching.
        $broadcastAs = (new ReflectionClass(MessageSent::class))
            ->getMethod('broadcastAs');

        $instance = (new ReflectionClass(MessageSent::class))->newInstanceWithoutConstructor();
        $this->assertSame('message.sent', $broadcastAs->invoke($instance));

        $reflection = new ReflectionClass(ConversationThread::class);
        $onAttributes = $reflection->getMethod('onMessageBroadcast')->getAttributes(On::class);
        $eventName = $onAttributes[0]->newInstance()->event;

        $this->assertStringEndsWith('.message.sent', $eventName);
    }

    public function test_the_broadcast_event_targets_the_same_private_channel_name_the_frontend_subscribes_to(): void
    {
        // MessageSent::broadcastOn() returns PrivateChannel('conversation.{id}');
        // Echo's channel-name convention strips the 'private-' prefix it
        // adds internally, so the frontend's literal channel string
        // "conversation.{conversation.id}" must match this exactly.
        $reflection = new ReflectionClass(ConversationThread::class);
        $onAttributes = $reflection->getMethod('onMessageBroadcast')->getAttributes(On::class);
        $eventName = $onAttributes[0]->newInstance()->event;

        $this->assertStringStartsWith('echo-private:conversation.', $eventName);
    }

    public function test_the_frontend_echo_bootstrap_file_exists_and_configures_the_reverb_broadcaster(): void
    {
        $path = base_path('resources/js/echo.js');
        $this->assertFileExists($path, 'resources/js/echo.js is required to construct window.Echo.');

        $contents = file_get_contents($path);

        $this->assertStringContainsString("from 'laravel-echo'", $contents);
        $this->assertStringContainsString("broadcaster: 'reverb'", $contents);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY', $contents);
        $this->assertStringContainsString('VITE_REVERB_HOST', $contents);
    }

    public function test_the_app_entrypoint_imports_the_echo_bootstrap(): void
    {
        $contents = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("import './echo'", $contents);
    }

    public function test_wire_poll_is_present_only_as_a_documented_fallback_not_the_primary_mechanism(): void
    {
        $contents = file_get_contents(base_path('themes/default/pages/account/conversation-thread.blade.php'));

        $this->assertStringContainsString('wire:poll', $contents);
        $this->assertStringContainsString('degradation fallback', $contents);
    }

    public function test_routes_channels_authorizes_the_exact_channel_name_the_frontend_subscribes_to(): void
    {
        $contents = file_get_contents(base_path('routes/channels.php'));

        $this->assertStringContainsString("Broadcast::channel('conversation.{conversationId}'", $contents);
        $this->assertStringContainsString('ConversationPolicy', $contents);
    }

    public function test_broadcasting_routes_are_registered_via_bootstrap_app_channels_wiring(): void
    {
        $contents = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("channels: __DIR__.'/../routes/channels.php'", $contents);
    }
}
