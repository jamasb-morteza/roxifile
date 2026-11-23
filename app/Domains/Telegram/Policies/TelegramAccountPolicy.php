<?php

namespace App\Domains\Telegram\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;

class TelegramAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TelegramAccount $telegramAccount): bool
    {
        return $telegramAccount->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TelegramAccount $telegramAccount): bool
    {
        return ! $telegramAccount->is_system
            && $telegramAccount->user_id === $user->id;
    }

    public function delete(User $user, TelegramAccount $telegramAccount): bool
    {
        return ! $telegramAccount->is_system
            && $telegramAccount->user_id === $user->id;
    }
}
