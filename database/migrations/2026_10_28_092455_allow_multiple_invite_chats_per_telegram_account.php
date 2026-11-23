<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX telegram_connected_invite_chat_unique ON telegram_connections (telegram_account_id, chat_id) WHERE method = 'invite' AND status = 'connected' AND chat_id IS NOT NULL AND deleted_at IS NULL");
        DB::statement('DROP INDEX telegram_connected_invite_target_unique');
    }

    public function down(): void
    {
        DB::statement("CREATE UNIQUE INDEX telegram_connected_invite_target_unique ON telegram_connections (telegram_account_id, target_user_id) WHERE method = 'invite' AND status = 'connected' AND target_user_id IS NOT NULL AND deleted_at IS NULL");
        DB::statement('DROP INDEX telegram_connected_invite_chat_unique');
    }
};
