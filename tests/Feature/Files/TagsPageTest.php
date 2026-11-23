<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_page_renders_for_inertia_visits(): void
    {
        $user = User::factory()->create();
        Tag::factory()->create(['user_id' => $user->id, 'name' => 'work']);

        $response = $this->actingAs($user)->get('/tags');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('tags/Index')
            ->has('tags', 1)
            ->where('tags.0.name', 'work')
            ->where('tags.0.files_count', 0));
    }

    public function test_files_page_renders_with_listing_props(): void
    {
        $user = User::factory()->create();
        File::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/files');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('files/Index')
            ->has('files.data', 2)
            ->has('connections')
            ->has('directories')
            ->has('filters'));
    }

    public function test_tag_listing_includes_file_counts(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $user->id, 'name' => 'work']);
        File::factory()->count(3)->create(['user_id' => $user->id])->each(
            fn (File $file) => $file->tags()->attach($tag),
        );

        $response = $this->actingAs($user)->getJson('/tags');

        $response->assertOk();
        $response->assertJsonPath('tags.0.files_count', 3);
    }
}
