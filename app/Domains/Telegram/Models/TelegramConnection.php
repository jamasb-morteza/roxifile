<?php

namespace App\Domains\Telegram\Models;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use Database\Factories\TelegramConnectionFactory;
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
 * @property string $encryption_mode
 * @property array<string, mixed>|null $vault_envelope
 * @property Carbon|null $encryption_locked_at
 * @property int $id
 * @property int $user_id
 * @property int $telegram_account_id
 * @property TelegramMethod $method
 * @property string $name
 * @property TelegramConnectionStatus $status
 * @property int|null $chat_id
 * @property ChatType|null $chat_type
 * @property string|null $chat_title
 * @property string|null $chat_username
 * @property Carbon|null $last_verified_at
 * @property string|null $provision_code_hash
 * @property Carbon|null $provision_code_expires_at
 * @property string|null $provision_code_encrypted
 * @property int|null $target_user_id
 * @property string|null $target_identifier
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read TelegramAccount $account
 * @property-read Collection<int, File> $files
 * @property-read Collection<int, TelegramConnectionAttempt> $attempts
 */
#[UseFactory(TelegramConnectionFactory::class)]
#[Fillable(['user_id', 'telegram_account_id', 'method', 'name', 'status', 'chat_id', 'chat_type', 'chat_title', 'chat_username', 'last_verified_at', 'provision_code_hash', 'provision_code_expires_at', 'provision_code_encrypted', 'target_user_id', 'target_identifier', 'last_error', 'last_error_at', 'metadata', 'encryption_mode', 'vault_envelope', 'encryption_locked_at'])]
class TelegramConnection extends Model
{
    protected $hidden = ['provision_code_hash', 'provision_code_encrypted', 'vault_envelope'];

    /** @use HasFactory<TelegramConnectionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vault_envelope' => 'array',
            'encryption_locked_at' => 'datetime',
            'method' => TelegramMethod::class,
            'status' => TelegramConnectionStatus::class,
            'chat_type' => ChatType::class,
            'chat_id' => 'integer',
            'last_verified_at' => 'datetime',
            'provision_code_expires_at' => 'datetime',
            'provision_code_encrypted' => 'encrypted',
            'target_user_id' => 'integer',
            'last_error_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function syncsCaptionTags(): bool
    {
        return (bool) ($this->metadata['sync_caption_tags'] ?? true);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /** @return HasMany<TelegramConnectionAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(TelegramConnectionAttempt::class);
    }
}
