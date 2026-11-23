<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Actions\SearchFiles;
use App\Domains\Files\Enums\FileType;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_search_matches_names_paths_and_descriptions(): void
    {
        $user = User::factory()->create();
        File::factory()->create([
            'user_id' => $user->id,
            'name' => 'annual-summary.pdf',
        ]);
        File::factory()->create([
            'user_id' => $user->id,
            'name' => 'field-notes.txt',
            'description' => 'Annual inspection notes',
        ]);
        $directory = Directory::factory()->forUser($user)->create(['name' => 'archive-folder']);
        File::factory()->inDirectory($directory)->create(['name' => 'source.txt']);

        $nameResults = $this->searchNames($user, 'summary');
        $descriptionResults = $this->searchNames($user, 'inspection');
        $pathResults = $this->searchNames($user, 'archive-folder', ['global' => true]);

        $this->assertSame(['annual-summary.pdf'], $nameResults);
        $this->assertSame(['field-notes.txt'], $descriptionResults);
        $this->assertSame(['source.txt'], $pathResults);
    }

    public function test_search_orders_exact_and_filename_matches_before_path_and_description_matches(): void
    {
        $user = User::factory()->create();
        File::factory()->create(['user_id' => $user->id, 'name' => 'invoice']);
        File::factory()->create(['user_id' => $user->id, 'name' => 'invoice-march.pdf']);
        $directory = Directory::factory()->forUser($user)->create(['name' => 'invoice-archive']);
        File::factory()->inDirectory($directory)->create(['name' => 'scan.pdf']);
        File::factory()->create([
            'user_id' => $user->id,
            'name' => 'memo.txt',
            'description' => 'See invoice attached',
        ]);

        $results = $this->searchNames($user, 'invoice', ['global' => true]);

        $this->assertSame(['invoice', 'invoice-march.pdf', 'scan.pdf', 'memo.txt'], $results);
    }

    public function test_search_filters_still_apply_to_matching_results(): void
    {
        $user = User::factory()->create();
        File::factory()->create([
            'user_id' => $user->id,
            'name' => 'invoice.pdf',
            'file_type' => FileType::Pdf,
        ]);
        File::factory()->create([
            'user_id' => $user->id,
            'name' => 'invoice.jpg',
            'file_type' => FileType::Image,
        ]);
        File::factory()->create([
            'name' => 'invoice-private.pdf',
            'file_type' => FileType::Pdf,
        ]);

        $results = $this->searchNames($user, 'invoice', ['file_type' => FileType::Pdf->value]);

        $this->assertSame(['invoice.pdf'], $results);
    }

    public function test_search_does_not_return_another_users_matching_file(): void
    {
        $owner = User::factory()->create();
        File::factory()->create([
            'name' => 'secret-record.txt',
            'description' => 'Owner only confidential details',
        ]);

        $this->actingAs($owner)
            ->getJson('/files?q=confidential&scope=all')
            ->assertOk()
            ->assertJsonPath('files.data', []);
    }

    public function test_postgresql_search_matches_a_filename_with_a_typo(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Trigram typo matching requires PostgreSQL.');
        }

        if (! DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            $this->markTestSkipped('pg_trgm is unavailable to this PostgreSQL test database.');
        }

        $user = User::factory()->create();
        File::factory()->create(['user_id' => $user->id, 'name' => 'quarterly-report.pdf']);

        $results = $this->searchNames($user, 'quaterly-report');

        $this->assertSame(['quarterly-report.pdf'], $results);
    }

    /**
     * @return list<string>
     */
    private function searchNames(User $user, string $query, array $filters = []): array
    {
        return app(SearchFiles::class)
            ->handle($user, array_merge(['q' => $query], $filters))
            ->getCollection()
            ->pluck('name')
            ->all();
    }
}
