<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Enums\FileType;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileSystemModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_directories_use_their_name_as_path(): void
    {
        $directory = Directory::factory()->create(['name' => 'Documents']);

        $this->assertSame('Documents', $directory->path);
        $this->assertNull($directory->parent_id);
    }

    public function test_child_directories_are_created_under_a_parent(): void
    {
        $parent = Directory::factory()->create(['name' => 'Documents']);
        $child = Directory::factory()->forParent($parent)->create(['name' => 'Invoices']);

        $this->assertSame('Documents/Invoices', $child->path);
        $this->assertTrue($parent->children->contains($child));
    }

    public function test_duplicate_sibling_names_are_rejected(): void
    {
        $parent = Directory::factory()->create(['name' => 'Documents']);

        Directory::factory()->forParent($parent)->create(['name' => 'Invoices']);

        $this->expectException(UniqueConstraintViolationException::class);
        Directory::factory()->forParent($parent)->create(['name' => 'Invoices']);
    }

    public function test_duplicate_top_level_names_are_rejected_for_the_same_user(): void
    {
        $user = User::factory()->create();

        Directory::factory()->forUser($user)->create(['name' => 'Documents']);

        $this->expectException(UniqueConstraintViolationException::class);
        Directory::factory()->forUser($user)->create(['name' => 'Documents']);
    }

    public function test_same_top_level_name_is_allowed_for_different_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $a = Directory::factory()->forUser($first)->create(['name' => 'Documents']);
        $b = Directory::factory()->forUser($second)->create(['name' => 'Documents']);

        $this->assertSame('Documents', $a->path);
        $this->assertSame('Documents', $b->path);
    }

    public function test_same_name_is_allowed_under_different_parents(): void
    {
        $a = Directory::factory()->create(['name' => 'A']);
        $b = Directory::factory()->create(['name' => 'B']);

        $childA = Directory::factory()->forParent($a)->create(['name' => 'Archive']);
        $childB = Directory::factory()->forParent($b)->create(['name' => 'Archive']);

        $this->assertSame('A/Archive', $childA->path);
        $this->assertSame('B/Archive', $childB->path);
    }

    public function test_name_is_reusable_after_soft_delete(): void
    {
        $parent = Directory::factory()->create(['name' => 'Documents']);
        Directory::factory()->forParent($parent)->create(['name' => 'Invoices'])->delete();

        $recreated = Directory::factory()->forParent($parent)->create(['name' => 'Invoices']);

        $this->assertSame('Documents/Invoices', $recreated->path);
    }

    public function test_files_belong_to_directory_account_connection_and_tags(): void
    {
        $user = User::factory()->create();
        $directory = Directory::factory()->forUser($user)->create();
        $file = File::factory()->uploaded()->inDirectory($directory)->create();
        $tag = Tag::factory()->forUser($user)->create();

        $file->tags()->attach($tag);

        $this->assertTrue($file->directory->is($directory));
        $this->assertSame($user->id, $file->user_id);
        $this->assertNotNull($file->account);
        $this->assertNotNull($file->connection);
        $this->assertTrue($file->tags->contains($tag));
        $this->assertTrue($tag->files->contains($file));
    }

    public function test_uploaded_files_carry_telegram_identifiers_and_status(): void
    {
        $file = File::factory()->uploaded()->create();

        $this->assertSame(FileStatus::Uploaded, $file->status);
        $this->assertNotNull($file->telegram_message_id);
        $this->assertNotNull($file->telegram_chat_id);
    }

    public function test_file_type_matches_mime_type_from_factory(): void
    {
        $file = File::factory()->create();

        $this->assertSame(FileType::fromMime($file->mime_type), $file->file_type);
    }

    public function test_soft_deleted_files_are_excluded_by_default(): void
    {
        $file = File::factory()->trashed()->create();

        $this->assertSoftDeleted($file);
    }
}
