<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS file_tags_tag_id_index ON file_tags (tag_id)');

        $similarityFunction = DB::selectOne("SELECT to_regprocedure('similarity(text,text)') IS NOT NULL AS available");

        if (! filter_var($similarityFunction?->available ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $extensionInstalled = DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();

            if ($extensionInstalled) {
                Log::warning('PostgreSQL file search will use substring matching because pg_trgm is outside the configured search path.');

                return;
            }

            $canCreateExtension = DB::selectOne("SELECT has_database_privilege(current_user, current_database(), 'CREATE') AS available");

            if (! filter_var($canCreateExtension?->available ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Log::warning('PostgreSQL file search will use substring matching because the migration role cannot install pg_trgm.');

                return;
            }

            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            $similarityFunction = DB::selectOne("SELECT to_regprocedure('similarity(text,text)') IS NOT NULL AS available");

            if (! filter_var($similarityFunction?->available ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Log::warning('PostgreSQL file search will use substring matching because pg_trgm is unavailable on this server.');

                return;
            }
        }

        DB::statement('CREATE INDEX IF NOT EXISTS files_name_trgm_idx ON files USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS files_description_trgm_idx ON files USING GIN (description gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS files_mime_type_trgm_idx ON files USING GIN (mime_type gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS files_file_type_trgm_idx ON files USING GIN (file_type gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS directories_path_trgm_idx ON directories USING GIN (path gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS tags_name_trgm_idx ON tags USING GIN (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS files_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS files_description_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS files_mime_type_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS files_file_type_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS directories_path_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS tags_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS file_tags_tag_id_index');
    }
};
