<?php

namespace App\Domains\Identity\Models;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Files\Models\Tag;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property Carbon|null $deletion_requested_at
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property string|null $google_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TelegramAccount> $telegramAccounts
 * @property-read Collection<int, TelegramConnection> $telegramConnections
 * @property-read Collection<int, Directory> $directories
 * @property-read Collection<int, File> $files
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, FileOperation> $fileOperations
 */
#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'email', 'password', 'is_super_admin', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deletion_requested_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Whether this user holds unrestricted administrative access
     * (e.g. starting the Telegram worker from the settings page).
     */
    public function isSuperAdmin(): bool
    {
        // Boolean cast backfills on refresh only; freshly created instances
        // keep null when the DB default applied.
        return (bool) $this->is_super_admin;
    }

    /**
     * Telegram accounts owned by this user.
     */
    /** @return HasMany<TelegramAccount, $this> */
    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    /**
     * Telegram storage connections owned by this user.
     */
    /** @return HasMany<TelegramConnection, $this> */
    public function telegramConnections(): HasMany
    {
        return $this->hasMany(TelegramConnection::class);
    }

    /**
     * Virtual filesystem directories owned by this user.
     */
    /** @return HasMany<Directory, $this> */
    public function directories(): HasMany
    {
        return $this->hasMany(Directory::class);
    }

    /**
     * Files owned by this user.
     */
    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * Tags owned by this user.
     */
    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Asynchronous file operations initiated by this user.
     */
    /** @return HasMany<FileOperation, $this> */
    public function fileOperations(): HasMany
    {
        return $this->hasMany(FileOperation::class);
    }
}
