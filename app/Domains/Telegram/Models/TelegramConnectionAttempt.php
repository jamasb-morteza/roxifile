<?php

namespace App\Domains\Telegram\Models;

use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use Database\Factories\TelegramConnectionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $telegram_connection_id
 * @property int|null $telegram_account_id
 * @property ConnectionAttemptType $type
 * @property ConnectionAttemptResult $result
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read TelegramConnection|null $connection
 * @property-read TelegramAccount|null $account
 */
#[UseFactory(TelegramConnectionAttemptFactory::class)]
#[Fillable(['telegram_connection_id', 'telegram_account_id', 'type', 'result', 'error', 'metadata'])]
class TelegramConnectionAttempt extends Model
{
    /** @use HasFactory<TelegramConnectionAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConnectionAttemptType::class,
            'result' => ConnectionAttemptResult::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TelegramConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(TelegramConnection::class, 'telegram_connection_id');
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
