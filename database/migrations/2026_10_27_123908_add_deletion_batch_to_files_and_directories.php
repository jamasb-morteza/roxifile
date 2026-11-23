<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['files', 'directories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('deletion_batch', 26)->nullable()->index();
            });
        }

        Schema::table('directories', function (Blueprint $table): void {
            $table->boolean('purging')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('directories', fn (Blueprint $table) => $table->dropColumn('purging'));

        foreach (['files', 'directories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('deletion_batch'));
        }
    }
};
