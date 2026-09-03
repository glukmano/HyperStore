<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $key
 * @property string $value
 * @property bool $is_encrypted
 * @property ?int $updated_by_user_id
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function getDecryptedValue(): string
    {
        if ($this->is_encrypted) {
            return Crypt::decryptString($this->value);
        }

        return $this->value;
    }

    public function setEncryptedValue(string $plain): void
    {
        $this->value = Crypt::encryptString($plain);
        $this->is_encrypted = true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
