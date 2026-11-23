<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_keeps_the_same_operation_and_rejects_missing_source_or_foreign_owners(): void
    {
        $file = File::factory()->create(['status' => 'failed']);
        Storage::disk('local')->put('telegram-upload/retry-test', 'original');
        $operation = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'upload', 'status' => 'failed',
            'metadata' => ['temp_path' => 'telegram-upload/retry-test', 'random_id' => 123],
        ]);
        $this->actingAs($file->user)->getJson('/file-operations')
            ->assertOk()->assertJsonPath('operations.0.can_retry_operation', true);
        Queue::fake([TelegramOperationJob::class]);

        $this->actingAs($file->user)->postJson('/file-operations/'.$operation->id.'/retry')
            ->assertAccepted()->assertJsonPath('operation_id', $operation->id);

        Queue::assertPushed(TelegramOperationJob::class, fn ($job): bool => $job->operationId === $operation->id);
        $this->assertSame(123, $operation->fresh()->metadata['random_id']);
        $this->actingAs(User::factory()->create())
            ->postJson('/file-operations/'.$operation->id.'/cancel')->assertForbidden();
    }

    public function test_pending_cancellation_cleans_source_and_an_uncertain_send_cannot_be_cancelled_blindly(): void
    {
        $file = File::factory()->create();
        $path = 'telegram-upload/pending-cancel';
        Storage::disk('local')->put($path, 'pending content');
        $operation = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'upload', 'status' => 'pending',
            'reserved_temp_bytes' => 15, 'metadata' => ['temp_path' => $path],
        ]);
        Queue::fake([TelegramOperationJob::class]);

        $this->actingAs($file->user)->postJson('/file-operations/'.$operation->id.'/cancel')->assertAccepted();
        $this->assertSame('cancelled', $operation->fresh()->status->value);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $operation->fresh()->reserved_temp_bytes);
        $operation->update(['status' => 'uploading', 'metadata' => ['send_started_at' => now()->toIso8601String(), 'temp_path' => $path]]);
        Storage::disk('local')->put($path, 'retain until review');
        $this->postJson('/file-operations/'.$operation->id.'/cancel')->assertUnprocessable();
        Storage::disk('local')->assertExists($path);
    }

    public function test_owned_operation_snapshots_can_be_requested_after_they_leave_recent_history(): void
    {
        $file = File::factory()->uploaded()->create();
        $old = FileOperation::factory()->create(['user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'download', 'status' => 'completed']);
        FileOperation::factory()->count(100)->create(['user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'download', 'status' => 'completed']);
        $this->actingAs($file->user)->getJson('/file-operations?ids[]='.$old->id)
            ->assertOk()->assertJsonCount(1, 'operations')->assertJsonPath('operations.0.operation_id', $old->id);
        $this->actingAs(User::factory()->create())->getJson('/file-operations?ids[]='.$old->id)
            ->assertOk()->assertJsonCount(0, 'operations');
    }
}
