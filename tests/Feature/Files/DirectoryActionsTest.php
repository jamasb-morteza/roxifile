<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DirectoryActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_creates_directories_with_materialized_paths(): void
    {
        $root = $this->createDirectory('Projects');

        $this->assertSame('Projects', $root['path']);

        $nested = $this->createDirectory('2026', $root['id']);

        $this->assertSame('Projects/2026', $nested['path']);
    }

    public function test_duplicate_sibling_names_are_rejected(): void
    {
        $root = $this->createDirectory('Projects');

        $response = $this->actingAs($this->user)->postJson('/directories', [
            'name' => 'Projects',
        ]);

        $response->assertStatus(422);
    }

    public function test_rename_rewrites_subtree_paths(): void
    {
        $root = $this->createDirectory('Projects');
        $child = $this->createDirectory('2026', $root['id']);

        $response = $this->actingAs($this->user)->patchJson("/directories/{$root['id']}/rename", [
            'name' => 'Work',
        ]);

        $response->assertOk();

        $this->assertSame('Work', Directory::find($root['id'])->path);
        $this->assertSame('Work/2026', Directory::find($child['id'])->path);
    }

    public function test_move_rewrites_subtree_paths(): void
    {
        $parent = $this->createDirectory('Parent');
        $moved = $this->createDirectory('Moved');
        $grandchild = $this->createDirectory('Inner', $moved['id']);

        $response = $this->actingAs($this->user)->patchJson("/directories/{$moved['id']}/move", [
            'parent_id' => $parent['id'],
        ]);

        $response->assertOk();

        $this->assertSame('Parent/Moved', Directory::find($moved['id'])->path);
        $this->assertSame('Parent/Moved/Inner', Directory::find($grandchild['id'])->path);
    }

    public function test_directory_cannot_be_moved_into_its_own_subtree(): void
    {
        $root = $this->createDirectory('Root');
        $child = $this->createDirectory('Child', $root['id']);

        $response = $this->actingAs($this->user)->patchJson("/directories/{$root['id']}/move", [
            'parent_id' => $child['id'],
        ]);

        $response->assertStatus(422);
    }

    public function test_cross_user_directory_access_is_denied(): void
    {
        $other = User::factory()->create();
        $foreign = Directory::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->user)
            ->patchJson("/directories/{$foreign->id}/rename", ['name' => 'Mine'])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->patchJson("/directories/{$foreign->id}/move", ['parent_id' => null])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->deleteJson("/directories/{$foreign->id}")
            ->assertForbidden();
    }

    public function test_trash_cycle_hides_then_restores_then_purges(): void
    {
        $root = $this->createDirectory('Temp');

        $this->actingAs($this->user)->deleteJson("/directories/{$root['id']}")->assertOk();
        $this->assertSoftDeleted('directories', ['id' => $root['id']]);

        $this->actingAs($this->user)->postJson("/directories/{$root['id']}/restore")->assertOk();
        $this->assertDatabaseHas('directories', ['id' => $root['id'], 'deleted_at' => null]);

        $this->actingAs($this->user)->deleteJson("/directories/{$root['id']}")->assertOk();
        $this->actingAs($this->user)->deleteJson("/directories/{$root['id']}/purge")->assertOk();
        $this->assertDatabaseMissing('directories', ['id' => $root['id']]);
    }

    public function test_renaming_a_wildcard_name_does_not_change_another_subtree(): void
    {
        $target = $this->createDirectory('فایل_%');
        $child = $this->createDirectory('Child', $target['id']);
        $other = $this->createDirectory('فایل_X');
        $otherChild = $this->createDirectory('Child', $other['id']);

        $this->actingAs($this->user)->patchJson('/directories/'.$target['id'].'/rename', ['name' => 'جدید'])->assertOk();

        $this->assertSame('جدید/Child', Directory::findOrFail($child['id'])->path);
        $this->assertSame('فایل_X/Child', Directory::findOrFail($otherChild['id'])->path);
    }

    public function test_root_lists_only_root_files(): void
    {
        $folder = $this->createDirectory('Nested');
        File::factory()->create(['user_id' => $this->user->id, 'name' => 'root.txt']);
        File::factory()->create(['user_id' => $this->user->id, 'directory_id' => $folder['id'], 'name' => 'nested.txt']);

        $this->actingAs($this->user)->get('/files')->assertInertia(
            fn (Assert $page) => $page->has('files.data', 1)->where('files.data.0.name', 'root.txt'),
        );
    }

    public function test_restoring_a_folder_does_not_restore_a_child_deleted_earlier(): void
    {
        $root = $this->createDirectory('Root');
        $old = $this->createDirectory('Old', $root['id']);
        $live = $this->createDirectory('Live', $root['id']);
        $file = File::factory()->create(['user_id' => $this->user->id, 'directory_id' => $live['id']]);
        $this->actingAs($this->user)->deleteJson('/directories/'.$old['id'])->assertOk();
        $this->deleteJson('/directories/'.$root['id'])->assertOk();

        $this->get('/files?trash=1')->assertInertia(fn (Assert $page) => $page->has('directories', 1));
        $this->get('/files?trash=1&directory_id='.$root['id'])->assertInertia(fn (Assert $page) => $page->has('directories', 2));
        $this->postJson('/directories/'.$root['id'].'/restore')->assertOk();

        $this->assertSoftDeleted('directories', ['id' => $old['id']]);
        $this->assertDatabaseHas('directories', ['id' => $live['id'], 'deleted_at' => null]);
        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    }

    public function test_guests_are_rejected(): void
    {
        $this->postJson('/directories', ['name' => 'Nope'])->assertUnauthorized();
    }

    /**
     * @return array{id: int, name: string, path: string}
     */
    private function createDirectory(string $name, ?int $parentId = null): array
    {
        $response = $this->actingAs($this->user)->postJson('/directories', [
            'name' => $name,
            'parent_id' => $parentId,
        ]);

        $response->assertCreated();

        return $response->json('directory');
    }
}
