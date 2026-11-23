<?php

namespace App\Domains\Files\Models;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Database\Factories\FileOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $reserved_temp_bytes
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $available_at
 * @property int $id
 * @property int $user_id
 * @property int|null $file_id
 * @property int|null $telegram_account_id
 * @property int|null $telegram_connection_id
 * @property FileOperationType $operation
 * @property FileOperationStatus $status
 * @property int $progress
 * @property int $attempts
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read File|null $file
 * @property-read TelegramAccount|null $account
 * @property-read TelegramConnection|null $connection
 */
#[UseFactory(FileOperationFactory::class)]
#[Fillable(['reserved_temp_bytes', 'file_id', 'telegram_account_id', 'telegram_connection_id', 'operation', 'status', 'progress', 'attempts', 'error', 'metadata', 'completed_at'])]
class FileOperation extends Model
{
    /** @use HasFactory<FileOperationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => FileOperationType::class,
            'status' => FileOperationStatus::class,
            'progress' => 'integer',
            'attempts' => 'integer',
            'reserved_temp_bytes' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'available_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    /** @return BelongsTo<TelegramConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(TelegramConnection::class, 'telegram_connection_id');
    }
}
