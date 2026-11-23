<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserved_temp_bytes')->default(0);
            $table->string('lease_token', 26)->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('available_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('file_operations', fn (Blueprint $table) => $table->dropColumn(['lease_token', 'lease_expires_at', 'available_at', 'reserved_temp_bytes']));
    }
};
