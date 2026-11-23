<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level ownership denials: a signed-in attacker must never mutate or
 * remove another user's files, singly or in bulk (spec §6, §45).
 */
class CrossUserDenialTest extends TestCase
{
    use RefreshDatabase;

    private User $victim;

    private User $attacker;

    private File $victimFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->victim = User::factory()->create();
        $this->attacker = User::factory()->create();
        $this->victimFile = File::factory()->create([
            'user_id' => $this->victim->id,
            'name' => 'victim.pdf',
            'description' => 'victim description',
        ]);
    }

    public function test_rename_of_another_users_file_is_denied(): void
    {
        $this->actingAs($this->attacker)
            ->patchJson("/files/{$this->victimFile->id}/rename", ['name' => 'hijacked.pdf'])
            ->assertForbidden();

        $this->assertSame('victim.pdf', $this->victimFile->fresh()->name);
    }

    public function test_move_of_another_users_file_is_denied(): void
    {
        $attackerDir = Directory::factory()->create(['user_id' => $this->attacker->id]);

        $this->actingAs($this->attacker)
            ->patchJson("/files/{$this->victimFile->id}/move", ['directory_id' => $attackerDir->id])
            ->assertForbidden();

        $this->assertNull($this->victimFile->fresh()->directory_id);
    }

    public function test_description_update_of_another_users_file_is_denied(): void
    {
        $this->actingAs($this->attacker)
            ->patchJson("/files/{$this->victimFile->id}/description", ['description' => 'tampered'])
            ->assertForbidden();

        $this->assertSame('victim description', $this->victimFile->fresh()->description);
    }

    public function test_bulk_move_never_touches_another_users_files(): void
    {
        $attackerFile = File::factory()->create(['user_id' => $this->attacker->id]);
        $attackerDir = Directory::factory()->create(['user_id' => $this->attacker->id]);

        $response = $this->actingAs($this->attacker)->postJson('/files/bulk/move', [
            'file_ids' => [$attackerFile->id, $this->victimFile->id],
            'directory_id' => $attackerDir->id,
        ]);

        $response->assertOk()->assertJson(['moved' => 1]);

        $this->assertSame($attackerDir->id, $attackerFile->fresh()->directory_id);
        $this->assertNull($this->victimFile->fresh()->directory_id);
    }

    public function test_bulk_delete_never_touches_another_users_files(): void
    {
        $attackerFile = File::factory()->create(['user_id' => $this->attacker->id]);

        $response = $this->actingAs($this->attacker)->postJson('/files/bulk/delete', [
            'file_ids' => [$attackerFile->id, $this->victimFile->id],
        ]);

        $response->assertOk()->assertJson(['deleted' => 1]);

        $this->assertSoftDeleted($attackerFile);
        $this->assertNull($this->victimFile->fresh()->deleted_at);
        $this->assertDatabaseHas('files', [
            'id' => $this->victimFile->id,
            'deleted_at' => null,
        ]);
    }

    public function test_bulk_move_cannot_target_another_users_directory(): void
    {
        $attackerFile = File::factory()->create(['user_id' => $this->attacker->id]);
        $victimDir = Directory::factory()->create(['user_id' => $this->victim->id]);

        $this->actingAs($this->attacker)
            ->postJson('/files/bulk/move', [
                'file_ids' => [$attackerFile->id],
                'directory_id' => $victimDir->id,
            ])
            ->assertNotFound();

        $this->assertNull($attackerFile->fresh()->directory_id);
    }

    public function test_guests_are_redirected_from_file_mutations(): void
    {
        $this->patchJson("/files/{$this->victimFile->id}/rename", ['name' => 'x.pdf'])
            ->assertUnauthorized();

        $this->postJson('/files/bulk/delete', ['file_ids' => [$this->victimFile->id]])
            ->assertUnauthorized();
    }
}
