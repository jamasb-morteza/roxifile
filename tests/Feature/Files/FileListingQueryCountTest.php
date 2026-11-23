<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The listing page must run a constant number of queries regardless of how
 * many rows are rendered — any growth with row count is an N+1 regression
 * (spec §45).
 */
class FileListingQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        DB::enableQueryLog();
    }

    public function test_listing_query_count_does_not_grow_with_row_count(): void
    {
        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
        ]);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
        ]);
        $tag = Tag::factory()->forUser($this->user)->create();
        $directory = Directory::factory()->create(['user_id' => $this->user->id]);

        $factory = fn (int $count) => File::factory()->count($count)->create([
            'user_id' => $this->user->id,
            'directory_id' => $directory->id,
            'telegram_account_id' => $account->id,
            'telegram_connection_id' => $connection->id,
            'telegram_chat_id' => $connection->chat_id,
        ])->each(function (File $file) use ($tag): void {
            $file->tags()->attach($tag);
        });

        // Baseline: a handful of rows (all inside the default 50/page window).
        $factory(5);
        $small = $this->listingQueryCount();

        // Six times the rows — including account/connection/tag joins — must
        // not add a single extra query.
        $factory(30);
        $large = $this->listingQueryCount();

        $this->assertSame(
            $small,
            $large,
            "Query count grew with row count ({$small} -> {$large}) — N+1 on the files listing.",
        );
    }

    public function test_bulk_tagging_loads_connection_privacy_policies_without_per_file_queries(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $this->user->id]);
        $files = File::factory()->count(3)->create(['user_id' => $this->user->id, 'telegram_connection_id' => $connection->id]);
        DB::flushQueryLog();

        $this->postJson('/files/bulk/tags', ['file_ids' => $files->modelKeys(), 'tags' => ['ordinary-tag']])->assertOk();

        $connectionQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'telegram_connections'));
        $this->assertLessThanOrEqual(1, count($connectionQueries));
    }

    private function listingQueryCount(): int
    {
        DB::flushQueryLog();

        $response = $this->get(route('files.index', ['scope' => 'all']));
        $response->assertOk();

        return count(DB::getQueryLog());
    }
}
