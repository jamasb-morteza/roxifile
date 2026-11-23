<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->string('encryption_mode', 24)->default('none');
            $table->json('vault_envelope')->nullable();
            $table->timestamp('encryption_locked_at')->nullable();
        });
        DB::table('telegram_connections')->whereIn('id', DB::table('files')->select('telegram_connection_id')->whereNotNull('telegram_connection_id'))
            ->update(['encryption_locked_at' => now()]);
        Schema::table('files', function (Blueprint $table): void {
            $table->string('encryption_header', 88)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('telegram_connections')->where('encryption_mode', '!=', 'none')->exists()) {
            throw new RuntimeException('Cannot remove encryption metadata while encrypted connections exist.');
        }
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('encryption_header');
        });
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->dropColumn(['encryption_mode', 'vault_envelope', 'encryption_locked_at']);
        });
    }
};
