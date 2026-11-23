<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileOperationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_user_and_file(): void
    {
        $operation = FileOperation::factory()->create();

        $this->assertInstanceOf(User::class, $operation->user);
        $this->assertInstanceOf(File::class, $operation->file);
    }

    public function test_operations_start_pending_with_zero_progress(): void
    {
        $operation = FileOperation::factory()->create();

        $this->assertSame(FileOperationType::Upload, $operation->operation);
        $this->assertSame(FileOperationStatus::Pending, $operation->status);
        $this->assertSame(0, $operation->progress);
        $this->assertSame(0, $operation->attempts);
        $this->assertNull($operation->completed_at);
    }

    public function test_completed_state_is_persisted(): void
    {
        $operation = FileOperation::factory()->completed()->create();

        $this->assertSame(FileOperationStatus::Completed, $operation->status);
        $this->assertNotNull($operation->completed_at);
    }

    public function test_connection_attempts_record_without_updating(): void
    {
        $attempt = TelegramConnectionAttempt::factory()->create();

        $this->assertNotNull($attempt->created_at);
        $this->assertNull($attempt->updated_at);
    }

    public function test_user_has_many_operations(): void
    {
        $user = User::factory()->create();
        $operation = FileOperation::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->fileOperations->contains($operation));
    }
}
