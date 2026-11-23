<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\AuthenticateRoxiTelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramSettingsController
{
    public function __invoke(Request $request, AuthenticateRoxiTelegramAccount $roxiLogin): Response
    {
        return Inertia::render('settings/Telegram', [
            'connections' => TelegramConnection::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name', 'metadata'])
                ->map(fn (TelegramConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'sync_caption_tags' => $connection->syncsCaptionTags(),
                ]),
            'roxi_username' => $request->user()->isSuperAdmin()
                ? ltrim(trim((string) config('telegram.roxi.username')), '@')
                : null,
            'roxi_login_step' => $request->user()->isSuperAdmin()
                ? $roxiLogin->step($request->session())->value
                : null,
            'roxi_login_open' => $request->user()->isSuperAdmin()
                && (bool) $request->session()->pull('roxi_login_open', false),
        ]);
    }
}
