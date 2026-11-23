<?php

namespace Tests\Feature\Settings;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_retains_cleanup_credentials_until_all_remote_files_are_removed(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $user = $connection->user;
        $live = File::factory()->uploaded()->create(['user_id' => $user->id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id]);
        $trash = File::factory()->uploaded()->create(['user_id' => $user->id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id]);
        $trash->delete();
        $other = File::factory()->uploaded()->create();
        $driver = new FakeTelegramStorageDriver;
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        Queue::fake([TelegramOperationJob::class]);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNotNull($user->fresh());
        $this->assertNotNull($user->fresh()->deletion_requested_at);
        $this->assertNotNull($connection->fresh());
        $this->actingAs($user->fresh())->postJson('/telegram/connections/invite', [])->assertForbidden();
        $this->artisan('telegram:cleanup-accounts')->assertSuccessful();
        $driver->throwOnNext = new \RuntimeException('CHAT_WRITE_FORBIDDEN');
        $jobs = [];
        Queue::assertPushed(TelegramOperationJob::class, function ($job) use (&$jobs): bool {
            $jobs[] = $job;

            return true;
        });
        foreach ($jobs as $job) {
            $job->handle(app(TelegramOperationProcessor::class));
        }
        $this->assertNotNull($user->fresh());
        $this->artisan('telegram:cleanup-accounts', ['--retry-failed' => true])->assertSuccessful();
        $operations = $user->fileOperations()->where('status', 'pending')->get();
        foreach ($operations as $operation) {
            (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        }
        $this->artisan('telegram:cleanup-accounts')->assertSuccessful();
        $this->assertNull($user->fresh());
        $this->assertNull(File::withTrashed()->find($live->id));
        $this->assertNull(File::withTrashed()->find($trash->id));
        $this->assertNotNull($other->fresh());
    }

    public function test_cleanup_deletes_a_known_remote_result_after_a_crash_and_resumes_a_cancelled_purge(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $driver = new FakeTelegramStorageDriver;
        $driver->contents[8819] = 'known remote upload';
        $driver->contents[8818] = 'cancelled purge content';
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $file = File::factory()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => null, 'status' => 'failed']);
        FileOperation::factory()->create([
            'user_id' => $connection->user_id, 'file_id' => $file->id,
            'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id,
            'operation' => 'upload', 'status' => 'failed', 'metadata' => ['send_started_at' => now()->toIso8601String(), 'upload_result' => ['message_id' => 8819]],
        ]);
        $cancelled = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 8818]);
        FileOperation::factory()->create([
            'user_id' => $connection->user_id, 'file_id' => $cancelled->id,
            'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id,
            'operation' => 'delete', 'status' => 'cancelled', 'metadata' => ['permanent' => true],
        ]);

        $this->actingAs($connection->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));
        $this->artisan('telegram:cleanup-accounts')->assertSuccessful();

        $this->assertNull($driver->contents[8819], 'The known remote object must be deleted before its identifiers disappear.');
        $this->assertNull($driver->contents[8818], 'The new account-deletion request supersedes an earlier purge cancellation.');
        $this->assertNull($connection->user->fresh());
    }
}
