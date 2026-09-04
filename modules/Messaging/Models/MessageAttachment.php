<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $message_id
 * @property int $media_id
 */
class MessageAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'media_id', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['message_id' => 'integer', 'media_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
