<?php

namespace App\Domains\Telegram\Models;

use Database\Factories\TelegramImportRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array{stage?: string, directory_id?: int|null, directory_path?: string, tags?: list<string>, description?: string|null, new_path?: string}|null $details
 * @property int $id
 * @property int $telegram_account_id
 * @property int $telegram_connection_id
 * @property int $user_id
 * @property int $chat_id
 * @property int $source_message_id
 * @property int $sender_id
 * @property int|null $prompt_message_id
 * @property int|null $managed_message_id
 * @property int|null $file_id
 * @property string $state
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property int $random_id
 * @property string|null $source_file_id
 * @property string|null $source_media_id
 * @property string $marker
 * @property string|null $last_error
 * @property Carbon $expires_at
 */
#[UseFactory(TelegramImportRequestFactory::class)]
#[Fillable(['telegram_account_id', 'telegram_connection_id', 'user_id', 'chat_id', 'source_message_id', 'sender_id',
    'prompt_message_id', 'managed_message_id', 'file_id', 'state', 'name', 'mime_type', 'size', 'random_id',
    'marker', 'source_caption', 'source_file_id', 'source_media_id', 'last_error', 'expires_at', 'details'])]
class TelegramImportRequest extends Model
{
    /** @use HasFactory<TelegramImportRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['details' => 'array', 'expires_at' => 'datetime', 'chat_id' => 'integer', 'source_message_id' => 'integer',
            'sender_id' => 'integer', 'prompt_message_id' => 'integer', 'managed_message_id' => 'integer',
            'size' => 'integer', 'random_id' => 'integer'];
    }
}
