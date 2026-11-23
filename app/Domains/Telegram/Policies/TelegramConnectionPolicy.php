<?php

namespace App\Domains\Telegram\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramConnection;

class TelegramConnectionPolicy
{
    public function view(User $user, TelegramConnection $telegramConnection): bool
    {
        return $telegramConnection->user_id === $user->id;
    }

    /**
     * Verify / reconnect / disconnect / rename.
     */
    public function manage(User $user, TelegramConnection $telegramConnection): bool
    {
        return $telegramConnection->user_id === $user->id;
    }

    public function delete(User $user, TelegramConnection $telegramConnection): bool
    {
        return $telegramConnection->user_id === $user->id;
    }
}
