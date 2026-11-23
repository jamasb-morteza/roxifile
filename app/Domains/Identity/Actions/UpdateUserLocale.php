<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Data\LocaleData;
use App\Domains\Identity\Models\User;

class UpdateUserLocale
{
    public function handle(User $user, LocaleData $data): void
    {
        $user->locale = $data->locale;
        $user->save();
    }
}
