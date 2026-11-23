<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileSystemPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_policies_enforce_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $directory = Directory::factory()->forUser($owner)->create();

        $this->assertTrue($owner->can('view', $directory));
        $this->assertTrue($owner->can('update', $directory));
        $this->assertTrue($owner->can('delete', $directory));
        $this->assertFalse($other->can('view', $directory));
        $this->assertFalse($other->can('update', $directory));
        $this->assertFalse($other->can('delete', $directory));
    }

    public function test_file_policies_enforce_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $file));
        $this->assertTrue($owner->can('update', $file));
        $this->assertTrue($owner->can('delete', $file));
        $this->assertFalse($other->can('view', $file));
        $this->assertFalse($other->can('update', $file));
        $this->assertFalse($other->can('delete', $file));
    }

    public function test_tag_policies_enforce_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tag = Tag::factory()->forUser($owner)->create();

        $this->assertTrue($owner->can('view', $tag));
        $this->assertTrue($owner->can('update', $tag));
        $this->assertTrue($owner->can('delete', $tag));
        $this->assertFalse($other->can('view', $tag));
        $this->assertFalse($other->can('update', $tag));
        $this->assertFalse($other->can('delete', $tag));
    }

    public function test_file_operation_policies_enforce_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $operation = FileOperation::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $operation));
        $this->assertFalse($other->can('view', $operation));
    }
}
