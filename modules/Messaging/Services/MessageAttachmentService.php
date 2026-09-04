<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Illuminate\Http\UploadedFile;
use Modules\Messaging\Exceptions\InvalidAttachmentException;
use Modules\Messaging\Models\Message;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * MIME validated via real content inspection (UploadedFile::getMimeType()
 * inspects the actual file content via finfo, never the client-declared
 * Content-Type/extension alone). Stored on MediaLibrary's private disk —
 * never public — served only via signed temporary URLs.
 */
final class MessageAttachmentService
{
    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function attach(Message $message, UploadedFile $file): Media
    {
        $mimeType = $file->getMimeType();

        if ($mimeType === null || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidAttachmentException("File type [{$mimeType}] is not allowed for message attachments.");
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new InvalidAttachmentException('Attachment exceeds the maximum allowed size.');
        }

        return $message->addMedia($file)
            ->usingFileName(bin2hex(random_bytes(16)).'.'.$file->getClientOriginalExtension())
            ->toMediaCollection('message_attachments', 'local');
    }

    public function temporaryUrl(Media $media): string
    {
        return $media->getTemporaryUrl(now()->addMinutes(10));
    }
}
