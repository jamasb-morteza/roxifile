<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table) {
            $table->bigInteger('target_user_id')->nullable();
            $table->string('target_identifier')->nullable();
            $table->text('provision_code_encrypted')->nullable();

            $table->index('target_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table) {
            $table->dropIndex(['target_user_id']);
            $table->dropColumn(['target_user_id', 'target_identifier', 'provision_code_encrypted']);
        });
    }
};
