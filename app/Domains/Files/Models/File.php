<?php

namespace App\Domains\Files\Models;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Enums\FileType;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @property Carbon|null $last_reconciled_at
 * @property string|null $deletion_batch
 * @property string|null $encryption_header
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $directory_id
 * @property int|null $telegram_account_id
 * @property int|null $telegram_connection_id
 * @property string $name
 * @property string $original_name
 * @property string|null $extension
 * @property string $mime_type
 * @property FileType $file_type
 * @property int $size
 * @property string|null $checksum
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property FileStatus $status
 * @property int|null $telegram_chat_id
 * @property int|null $telegram_message_id
 * @property string|null $telegram_media_id
 * @property string|null $telegram_file_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Directory|null $directory
 * @property-read TelegramAccount|null $account
 * @property-read TelegramConnection|null $connection
 * @property-read Collection<int, Tag> $tags
 */
#[UseFactory(FileFactory::class)]
#[Fillable(['user_id', 'directory_id', 'telegram_account_id', 'telegram_connection_id', 'name', 'original_name', 'extension', 'mime_type', 'file_type', 'size', 'checksum', 'description', 'metadata', 'status', 'telegram_chat_id', 'telegram_message_id', 'telegram_media_id', 'telegram_file_id', 'encryption_header'])]
class File extends Model
{
    public const ARTWORK_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/gif',
    ];

    /** @use HasFactory<FileFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_type' => FileType::class,
            'status' => FileStatus::class,
            'size' => 'integer',
            'metadata' => 'array',
            'last_reconciled_at' => 'datetime',
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (File $file): void {
            $file->uuid ??= (string) Str::uuid7();
        });
    }

    public function rejectPlaintextMetadata(): void
    {
        if ($this->getRelationValue('connection')?->encryption_mode === 'contents_and_metadata') {
            throw ValidationException::withMessages(['file' => 'Unlock this file and encrypt metadata changes on your device.']);
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
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

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'file_tags');
    }
}
