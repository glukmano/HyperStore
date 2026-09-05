<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Modules\Messaging\Models\MessageAttachment;
use Modules\Messaging\Services\ConversationPolicy;

/**
 * The one HTTP path that ever hands out a signed temporary URL for a
 * private message attachment. ConversationPolicy::view() is checked BEFORE
 * the signed URL is generated — a hard-to-guess URL is never treated as
 * authorization by itself (Owner Delta item L).
 */
class MessageAttachmentController extends Controller
{
    public function show(MessageAttachment $attachment, ConversationPolicy $policy): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $message = $attachment->message;
        $conversation = $message?->conversation;

        if ($message === null || $conversation === null) {
            abort(404);
        }

        if (! $policy->view($user, $conversation)) {
            abort(403, 'You are not authorized to view this attachment.');
        }

        $media = $attachment->media;

        if ($media === null) {
            abort(404);
        }

        return redirect()->away($media->getTemporaryUrl(now()->addMinutes(10)));
    }
}
