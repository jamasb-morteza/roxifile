<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_libraries', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('directory_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('media_playlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('file_ids');
            $table->timestamps();
        });

        Schema::create('media_track_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->boolean('favorite')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->primary(['user_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_track_preferences');
        Schema::dropIfExists('media_playlists');
        Schema::dropIfExists('media_libraries');
    }
};
