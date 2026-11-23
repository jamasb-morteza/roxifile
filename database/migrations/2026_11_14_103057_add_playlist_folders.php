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
        Schema::create('media_playlist_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('media_playlist_folders')->nullOnDelete();
            $table->string('name', 100);
        });

        Schema::table('media_playlists', function (Blueprint $table): void {
            $table->foreignId('folder_id')->nullable()->constrained('media_playlist_folders')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_playlists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('media_playlist_folders');
    }
};
