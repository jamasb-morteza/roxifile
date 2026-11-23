<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX telegram_connected_invite_target_unique ON telegram_connections (telegram_account_id, target_user_id) WHERE method = 'invite' AND status = 'connected' AND target_user_id IS NOT NULL AND deleted_at IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS telegram_connected_invite_target_unique');
    }
};
