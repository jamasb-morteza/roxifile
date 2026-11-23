<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\EnsureRoxiAccount;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramConnectionsPageController
{
    public function __invoke(Request $request, EnsureRoxiAccount $ensureRoxiAccount): Response
    {
        ($ensureRoxiAccount)();

        $connections = TelegramConnection::query()
            ->withCount(['files' => fn ($query) => $query->withTrashed()])
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('telegram/Connections', [
            'contents_encryption_enabled' => (bool) config('telegram.contents_encryption_enabled'),
            'connections' => $connections->map(fn (TelegramConnection $c): array => [
                'id' => $c->id,
                'encryption_mode' => $c->encryption_mode,
                'vault_envelope' => $c->vault_envelope,
                'encryption_locked' => $c->encryption_locked_at !== null,
                'name' => $c->name,
                'method' => $c->method->value,
                'status' => $c->status->value,
                'chat_title' => $c->chat_title,
                'chat_type' => $c->chat_type?->value,
                'files_count' => $c->files_count,
                'last_verified_at' => $c->last_verified_at?->toIso8601String(),
                'last_error' => $c->last_error !== null ? TelegramErrors::message($c->last_error) : null,
                'provision_code_masked' => $this->maskedCode($c),
                'provision_code_expires_at' => $c->provision_code_expires_at?->toIso8601String(),
                'provisioning_state' => $this->provisioningState($c),
                'needs_storage_chat' => ($c->metadata['storage_mode'] ?? null) === 'existing_chat' && $c->chat_id === null,
                'identity_verified' => ! empty($c->metadata['identity_verified_at']),
                'personal_login_step' => $c->method === TelegramMethod::Personal ? ($c->metadata['personal_login_step'] ?? null) : null,
                'setup_mode' => $c->metadata['setup_mode'] ?? 'existing',
                'chat_setup' => [
                    'status' => $c->metadata['chat_setup']['status'] ?? null,
                    'chats' => $c->metadata['chat_setup']['chats'] ?? [],
                    'error' => isset($c->metadata['chat_setup']['error']) ? TelegramErrors::message((string) $c->metadata['chat_setup']['error']) : null,
                    'join_link' => $c->chat_id === null ? ($c->metadata['created_chat_invite_link'] ?? null) : null,
                    'created_title' => $c->metadata['created_chat_title'] ?? null,
                    'group_name' => $c->metadata['group_name'] ?? null,
                ],
            ])->all(),
            'roxi_username' => ltrim((string) config('telegram.roxi.username'), '@'),
        ]);
    }

    /**
     * Last four characters of a verified invite connection's retained
     * provisioning code (ADR-0001); never the full code, never plaintext
     * ciphertext to the browser.
     */
    private function maskedCode(TelegramConnection $connection): ?string
    {
        if ($connection->method !== TelegramMethod::Invite || $connection->provision_code_encrypted === null) {
            return null;
        }

        return substr($connection->provision_code_encrypted, -4);
    }

    private function provisioningState(TelegramConnection $connection): ?string
    {
        if ($connection->method !== TelegramMethod::Roxi) {
            return null;
        }

        $state = $connection->metadata['provisioning_state'] ?? null;

        if ($state === null && $connection->provision_code_hash !== null) {
            $state = 'awaiting_code';
        }

        if (in_array($state, ['awaiting_code', 'awaiting_confirmation_delivery', 'awaiting_confirmation'], true)
            && ($connection->provision_code_expires_at === null || $connection->provision_code_expires_at->lessThanOrEqualTo(now()))) {
            return 'expired';
        }

        return $state;
    }
}
