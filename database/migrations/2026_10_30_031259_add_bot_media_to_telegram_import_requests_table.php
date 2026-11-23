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
        Schema::table('telegram_import_requests', function (Blueprint $table): void {
            $table->text('source_file_id')->nullable();
            $table->text('source_media_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_import_requests', function (Blueprint $table): void {
            $table->dropColumn(['source_file_id', 'source_media_id']);
        });
    }
};
