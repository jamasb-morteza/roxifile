<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Actions\SearchFiles;
use App\Domains\Files\Data\SearchFilesData;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TagsSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_tag_crud_endpoints(): void
    {
        $created = $this->actingAs($this->user)->postJson('/tags', ['name' => 'invoices']);
        $created->assertCreated();
        $tagId = $created->json('tag.id') ?? $created->json('id');
        $this->assertNotNull($tagId);

        $this->actingAs($this->user)
            ->patchJson("/tags/{$tagId}", ['name' => 'bills'])
            ->assertOk();

        $this->assertDatabaseHas('tags', ['id' => $tagId, 'name' => 'bills']);

        $this->actingAs($this->user)->deleteJson("/tags/{$tagId}")->assertOk();
        $this->assertSoftDeleted('tags', ['id' => $tagId]);
    }

    public function test_a_deleted_tag_name_can_be_created_again(): void
    {
        $created = $this->actingAs($this->user)->postJson('/tags', ['name' => 'reusable'])->assertCreated();
        $tagId = $created->json('tag.id') ?? $created->json('id');
        $this->deleteJson('/tags/'.$tagId)->assertOk();

        $this->postJson('/tags', ['name' => 'reusable'])->assertCreated();

        $this->getJson('/tags')->assertJsonCount(1, 'tags');
    }

    public function test_renaming_into_an_existing_tag_returns_the_surviving_tag(): void
    {
        $old = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'old']);
        $target = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'target']);
        $file = File::factory()->create(['user_id' => $this->user->id]);
        $file->tags()->attach($old);

        $this->actingAs($this->user)->patchJson('/tags/'.$old->id, ['name' => 'target'])
            ->assertOk()->assertJsonPath('tag.id', $target->id);

        $this->assertSame([$target->id], $file->fresh()->tags->modelKeys());
    }

    public function test_tags_are_scoped_per_user(): void
    {
        $other = User::factory()->create();
        $foreign = Tag::factory()->create(['user_id' => $other->id, 'name' => 'theirs']);

        $this->actingAs($this->user)->getJson('/tags')->assertOk()->assertJsonCount(0, 'tags');

        // The other user's tag cannot be mutated.
        $this->actingAs($this->user)
            ->patchJson("/tags/{$foreign->id}", ['name' => 'mine'])
            ->assertForbidden();
    }

    public function test_bulk_tagging_updates_only_the_selected_owned_files(): void
    {
        $owned = File::factory()->count(2)->create(['user_id' => $this->user->id]);
        $foreign = File::factory()->create();

        $this->actingAs($this->user)->postJson('/files/bulk/tags', [
            'file_ids' => [...$owned->modelKeys(), $foreign->id], 'tags' => ['work', 'work'],
        ])->assertOk()->assertJsonPath('updated', 2);

        foreach ($owned as $file) {
            $this->assertSame(['work'], $file->fresh()->tags->pluck('name')->all());
        }
        $this->assertCount(0, $foreign->fresh()->tags);
    }

    public function test_attach_and_detach_tags_on_a_file(): void
    {
        $file = File::factory()->create(['user_id' => $this->user->id]);

        $attach = $this->actingAs($this->user)->postJson("/files/{$file->id}/tags", [
            'tags' => ['work', 'urgent'],
        ]);

        $attach->assertOk();
        $this->assertSame(['work', 'urgent'], $file->fresh()->tags->pluck('name')->all());

        $tagId = $file->fresh()->tags->firstWhere('name', 'urgent')->id;

        $this->actingAs($this->user)->deleteJson("/files/{$file->id}/tags/{$tagId}")->assertOk();
        $this->assertSame(['work'], $file->fresh()->tags->pluck('name')->all());
    }

    public function test_cross_user_file_cannot_be_tagged(): void
    {
        $other = User::factory()->create();
        $foreign = File::factory()->create(['user_id' => $other->id]);

        $this->actingAs($this->user)
            ->postJson("/files/{$foreign->id}/tags", ['tags' => ['mine']])
            ->assertForbidden();
    }

    public function test_search_text_matches_the_tag_label(): void
    {
        $file = File::factory()->create(['user_id' => $this->user->id, 'name' => 'report.pdf']);
        $tag = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'finance']);
        $file->tags()->attach($tag);

        $this->actingAs($this->user)->get('/files?q=finance')->assertInertia(
            fn (AssertableInertia $page) => $page->has('files.data', 1)->where('files.data.0.name', 'report.pdf'),
        );
    }

    public function test_search_filters_by_name_and_tag(): void
    {
        $invoice = File::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'invoice-january.pdf',
        ]);
        $photo = File::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'holiday.jpg',
        ]);

        $financeTag = Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'finance',
        ]);
        $invoice->tags()->attach($financeTag);

        $search = fn (array $overrides = []): array => app(SearchFiles::class)
            ->handle(
                $this->user,
                new SearchFilesData(
                    q: $overrides['q'] ?? null,
                    fileType: null,
                    mimeType: null,
                    tagIds: $overrides['tag_ids'] ?? [],
                    telegramAccountId: null,
                    telegramConnectionId: null,
                    createdAfter: null,
                    createdBefore: null,
                    directoryId: null,
                    sort: null,
                    direction: null,
                    perPage: 50,
                    trash: false,
                )->toFilters(),
            )
            ->getCollection()
            ->pluck('name')
            ->all();

        $this->assertSame(['invoice-january.pdf'], $search(['q' => 'invoice']));
        $this->assertSame(['invoice-january.pdf'], $search(['tag_ids' => [$financeTag->id]]));
        $this->assertCount(2, $search());
    }
}
