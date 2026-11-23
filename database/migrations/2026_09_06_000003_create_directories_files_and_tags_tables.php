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
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('directories')->cascadeOnDelete();
            $table->string('name');
            $table->string('path')->default('');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('path');
        });

        // Sibling-name uniqueness including top level (parent_id NULL -> 0),
        // ignoring soft-deleted rows. Raw SQL: portable expression + partial
        // index across PostgreSQL (production) and SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX directories_sibling_names_unique '
            .'ON directories (user_id, COALESCE(parent_id, 0), name) WHERE deleted_at IS NULL'
        );

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('directory_id')->nullable()->constrained('directories')->nullOnDelete();
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
            $table->foreignId('telegram_connection_id')->nullable()->constrained('telegram_connections')->nullOnDelete();

            $table->string('name');
            $table->string('original_name');
            $table->string('extension', 16)->nullable();
            $table->string('mime_type');
            $table->string('file_type', 16);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('pending');

            $table->bigInteger('telegram_chat_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->text('telegram_media_id')->nullable();
            $table->text('telegram_file_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'directory_id']);
            $table->index('telegram_account_id');
            $table->index('telegram_connection_id');
            $table->index('mime_type');
            $table->index('file_type');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('deleted_at');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('file_tags', function (Blueprint $table) {
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['file_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('files');
        DB::statement('DROP INDEX IF EXISTS directories_sibling_names_unique');
        Schema::dropIfExists('directories');
    }
};
