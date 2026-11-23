<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Services\DirectoryPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryPathServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_returns_the_name_for_top_level_directories(): void
    {
        $directory = Directory::factory()->create(['name' => 'Documents']);

        $this->assertSame('Documents', DirectoryPathService::build($directory));
    }

    public function test_build_joins_all_ancestors(): void
    {
        $root = Directory::factory()->create(['name' => 'Documents']);
        $mid = Directory::factory()->forParent($root)->create(['name' => 'Invoices']);
        $leaf = Directory::factory()->forParent($mid)->create(['name' => '2026']);

        $this->assertSame('Documents/Invoices/2026', DirectoryPathService::build($leaf));
    }

    public function test_rename_rewrites_the_subtree(): void
    {
        $root = Directory::factory()->create(['name' => 'Documents']);
        $mid = Directory::factory()->forParent($root)->create(['name' => 'Invoices']);
        $leaf = Directory::factory()->forParent($mid)->create(['name' => '2026']);

        DirectoryPathService::rename($mid, 'Bills');

        $this->assertSame('Documents/Bills', $mid->fresh()->path);
        $this->assertSame('Documents/Bills/2026', $leaf->fresh()->path);
    }

    public function test_move_rewrites_the_subtree_under_the_new_parent(): void
    {
        $source = Directory::factory()->create(['name' => 'Source']);
        $target = Directory::factory()->create(['name' => 'Target']);
        $child = Directory::factory()->forParent($source)->create(['name' => 'Reports']);
        $grandchild = Directory::factory()->forParent($child)->create(['name' => 'Q1']);

        DirectoryPathService::move($child, $target);

        $this->assertSame($target->id, $child->fresh()->parent_id);
        $this->assertSame('Target/Reports', $child->fresh()->path);
        $this->assertSame('Target/Reports/Q1', $grandchild->fresh()->path);
    }

    public function test_move_to_top_level_makes_the_name_the_path(): void
    {
        $target = Directory::factory()->create(['name' => 'Target']);
        $child = Directory::factory()->forParent($target)->create(['name' => 'Loose']);

        DirectoryPathService::move($child, null);

        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame('Loose', $child->fresh()->path);
    }

    public function test_rename_of_root_directory_keeps_descendants_consistent(): void
    {
        $root = Directory::factory()->create(['name' => 'Old']);
        $leaf = Directory::factory()->forParent($root)->create(['name' => 'A']);

        DirectoryPathService::rename($root, 'New');

        $this->assertSame('New', $root->fresh()->path);
        $this->assertSame('New/A', $leaf->fresh()->path);
    }

    public function test_set_path_rewrites_the_subtree(): void
    {
        $root = Directory::factory()->create(['name' => 'Old']);
        $leaf = Directory::factory()->forParent($root)->create(['name' => 'A']);

        DirectoryPathService::setPath($root, 'New');

        $this->assertSame('New', $root->fresh()->path);
        $this->assertSame('New/A', $leaf->fresh()->path);
    }
}
