<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_import_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('source_message_id');
            $table->bigInteger('sender_id');
            $table->bigInteger('prompt_message_id')->nullable();
            $table->bigInteger('managed_message_id')->nullable();
            $table->foreignId('file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 24)->default('pending');
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->bigInteger('random_id');
            $table->string('marker')->unique();
            $table->text('source_caption')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['telegram_account_id', 'chat_id', 'source_message_id'], 'telegram_import_source_unique');
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_import_requests');
    }
};
