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
        Schema::create('telegram_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_account_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->string('name');
            $table->string('status', 24)->default('pending');
            $table->bigInteger('chat_id')->nullable();
            $table->string('chat_type', 16)->nullable();
            $table->string('chat_title')->nullable();
            $table->string('chat_username')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('provision_code_hash')->nullable();
            $table->timestamp('provision_code_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('telegram_account_id');
            $table->index('chat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_connections');
    }
};
