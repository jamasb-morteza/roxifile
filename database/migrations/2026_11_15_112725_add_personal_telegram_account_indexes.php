<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX telegram_personal_account_owner_unique ON telegram_accounts (user_id) WHERE method = 'personal' AND is_system = false AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX telegram_personal_connection_account_unique ON telegram_connections (telegram_account_id) WHERE method = 'personal' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS telegram_personal_connection_account_unique');
        DB::statement('DROP INDEX IF EXISTS telegram_personal_account_owner_unique');
    }
};
