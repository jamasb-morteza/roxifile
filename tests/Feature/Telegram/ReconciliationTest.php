<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Actions\ReconcileConnection;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\ReconcileTelegramStorage;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramStorageDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeTelegramStorageDriver;

        $this->app->bind(StorageManager::class, function (): StorageManager {
            return new class($this->driver) extends StorageManager
            {
                public function __construct(private readonly FakeTelegramStorageDriver $fake) {}

                public function for(TelegramConnection $connection): TelegramStorageDriver
                {
                    return $this->fake;
                }
            };
        });
    }

    public function test_missing_message_flags_file_metadata_without_deleting_it(): void
    {
        $file = File::factory()->uploaded()->create(['metadata' => null]);
        $this->driver->existsResult = false;

        $flagged = (new ReconcileTelegramStorage(batchSize: 100))->handle(app(StorageManager::class));

        $this->assertSame(1, $flagged);

        $file->refresh();

        $this->assertNotSoftDeleted($file);
        $this->assertSame(FileStatus::Uploaded, $file->status);
        $this->assertSame('message_missing', $file->metadata['reconciliation']['kind']);
        $this->assertNotEmpty($file->metadata['reconciliation']['flagged_at']);
    }

    public function test_existing_message_clears_a_previous_flag(): void
    {
        $file = File::factory()->uploaded()->create([
            'metadata' => ['reconciliation' => ['flagged_at' => '2026-01-01T00:00:00+00:00', 'kind' => 'message_missing']],
        ]);
        $this->driver->existsResult = true;

        (new ReconcileTelegramStorage(batchSize: 100))->handle(app(StorageManager::class));

        $file->refresh();

        $this->assertArrayNotHasKey('reconciliation', $file->metadata ?? []);
    }

    public function test_transient_errors_never_flag_files(): void
    {
        $file = File::factory()->uploaded()->create(['metadata' => null]);

        // Network-style faults are classified retryable (spec §43).
        $this->driver->throwOnNext = new \RuntimeException('connection reset by peer');

        (new ReconcileTelegramStorage(batchSize: 100))->handle(app(StorageManager::class));

        $file->refresh();

        $this->assertNull($file->metadata);
        $this->assertSame(FileStatus::Uploaded, $file->status);
    }

    public function test_unknown_existence_does_not_flag_or_reorder_the_file(): void
    {
        $file = File::factory()->uploaded()->create(['metadata' => null, 'updated_at' => now()->subDay()]);
        $originalTime = $file->updated_at->toIso8601String();
        $this->driver->existsResult = null;

        (new ReconcileTelegramStorage)->handle(app(StorageManager::class));

        $this->assertNull($file->fresh()->metadata);
        $this->assertSame($originalTime, $file->fresh()->updated_at->toIso8601String());
    }

    public function test_reconcile_command_dispatches_the_sweep_to_the_telegram_queue(): void
    {
        Queue::fake();

        $this->artisan('telegram:reconcile')->assertSuccessful();

        Queue::assertPushed(ReconcileTelegramStorage::class);
    }

    public function test_manual_mtproto_verification_is_queued_instead_of_opening_a_runtime_in_http(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'roxi']);
        Queue::fake([VerifyTelegramConnectionJob::class]);

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify')
            ->assertAccepted()->assertJsonPath('queued', true);

        Queue::assertPushed(VerifyTelegramConnectionJob::class, fn ($job): bool => $job->connectionId === $connection->id);
    }

    public function test_stale_and_unverified_connections_get_verify_jobs(): void
    {
        Queue::fake();

        $stale = TelegramConnection::factory()->create([
            'last_verified_at' => now()->subHours(25),
        ]);
        $neverVerified = TelegramConnection::factory()->create([
            'last_verified_at' => null,
        ]);
        $fresh = TelegramConnection::factory()->create([
            'last_verified_at' => now()->subHour(),
        ]);

        $this->artisan('telegram:verify-stale')->assertSuccessful();

        Queue::assertPushed(VerifyTelegramConnectionJob::class, fn (VerifyTelegramConnectionJob $job): bool => $job->connectionId === $stale->id);
        Queue::assertPushed(VerifyTelegramConnectionJob::class, fn (VerifyTelegramConnectionJob $job): bool => $job->connectionId === $neverVerified->id);
        Queue::assertNotPushed(VerifyTelegramConnectionJob::class, fn (VerifyTelegramConnectionJob $job): bool => $job->connectionId === $fresh->id);
    }

    public function test_reconcile_connection_records_reconcile_attempt_and_refreshes_verification(): void
    {
        $connection = TelegramConnection::factory()->create([
            'status' => TelegramConnectionStatus::Connected,
            'last_verified_at' => now()->subDays(30),
        ]);

        $connection = app(ReconcileConnection::class)->handle($connection);

        $connection->refresh();

        $this->assertSame(TelegramConnectionStatus::Connected, $connection->status);
        $this->assertTrue($connection->last_verified_at->gt(now()->subMinute()));

        $attempt = $connection->attempts()->latest('id')->firstOrFail();
        $this->assertSame(ConnectionAttemptType::Reconcile, $attempt->type);
        $this->assertSame(ConnectionAttemptResult::Success, $attempt->result);
    }

    public function test_reconcile_connection_records_failure_attempt_on_definitive_error(): void
    {
        $connection = TelegramConnection::factory()->create([
            'status' => TelegramConnectionStatus::Connected,
        ]);

        $this->driver->throwOnNext = new \RuntimeException('peer_id_invalid');
        $connection = app(ReconcileConnection::class)->handle($connection);

        $attempt = $connection->attempts()->latest('id')->firstOrFail();
        $this->assertSame(ConnectionAttemptType::Reconcile, $attempt->type);
        $this->assertSame(ConnectionAttemptResult::Failure, $attempt->result);
    }
}
