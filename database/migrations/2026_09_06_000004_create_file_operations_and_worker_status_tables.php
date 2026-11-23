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
        Schema::create('file_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
            $table->foreignId('telegram_connection_id')->nullable()->constrained('telegram_connections')->nullOnDelete();
            $table->string('operation', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('file_id');
        });

        Schema::create('telegram_connection_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_connection_id')->nullable()->constrained('telegram_connections')->nullOnDelete();
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
            $table->string('type', 16);
            $table->string('result', 16);
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('telegram_worker_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pid')->nullable();
            $table->string('hostname')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->boolean('roxi_connected')->default(false);
            $table->boolean('session_loaded')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_successful_operation_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedInteger('reconnect_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_worker_status');
        Schema::dropIfExists('telegram_connection_attempts');
        Schema::dropIfExists('file_operations');
    }
};
