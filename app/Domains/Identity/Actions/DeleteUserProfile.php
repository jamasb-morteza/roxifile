<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Files\Actions\CleanupUserStorage;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteUserProfile
{
    public function __construct(private readonly CleanupUserStorage $cleanup) {}

    /**
     * Delete the user's account and invalidate their session.
     */
    public function handle(User $user): bool
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->forceFill(['deletion_requested_at' => $user->deletion_requested_at ?? now(), 'remember_token' => null])->save();
            $user->telegramConnections()->update(['status' => 'disabled', 'provision_code_hash' => null, 'provision_code_encrypted' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();
        $completed = false;
        try {
            if (! $user->files()->withTrashed()->exists() && ! $user->fileOperations()->exists()) {
                $completed = $this->cleanup->handle($user->refresh());
            }
        } catch (\Throwable $error) {
            Log::warning('Account cleanup will resume from its durable request.', [
                'user_id' => $user->id, 'error' => TelegramErrors::message($error->getMessage()),
            ]);
        }

        return $completed;
    }
}
