<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('bot_update_offset')->default(0);
            $table->timestamp('bot_polled_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_accounts', fn (Blueprint $table) => $table->dropColumn(['bot_update_offset', 'bot_polled_at']));
    }
};
