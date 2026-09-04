<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $faq_id
 * @property string $locale
 * @property string $question
 * @property string $answer
 */
class FaqTranslation extends Model
{
    protected $fillable = ['faq_id', 'locale', 'question', 'answer'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['faq_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Faq, $this>
     */
    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class, 'faq_id');
    }
}
