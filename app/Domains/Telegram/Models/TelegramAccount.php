<?php

namespace App\Domains\Telegram\Models;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use Database\Factories\TelegramAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $bot_update_offset
 * @property Carbon|null $bot_polled_at
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property TelegramMethod $method
 * @property bool $is_system
 * @property TelegramAccountStatus $status
 * @property string|null $credentials
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, TelegramConnection> $connections
 * @property-read Collection<int, File> $files
 */
#[UseFactory(TelegramAccountFactory::class)]
#[Fillable(['user_id', 'name', 'method', 'is_system', 'status', 'credentials', 'last_error', 'last_error_at', 'metadata'])]
class TelegramAccount extends Model
{
    protected $hidden = ['credentials'];

    /** @use HasFactory<TelegramAccountFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => TelegramMethod::class,
            'status' => TelegramAccountStatus::class,
            'is_system' => 'boolean',
            'credentials' => 'encrypted',
            'bot_update_offset' => 'integer',
            'bot_polled_at' => 'datetime',
            'last_error_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TelegramConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(TelegramConnection::class);
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }
}
