<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
        });

        DB::table('files')->select(['id', 'created_at'])->chunkById(500, function ($files): void {
            foreach ($files as $file) {
                DB::table('files')->where('id', $file->id)->update([
                    'uuid' => (string) Str::uuid7($file->created_at ? Carbon::parse($file->created_at) : null),
                ]);
            }
        });

        Schema::table('files', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropUnique('files_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
