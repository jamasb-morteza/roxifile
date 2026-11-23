<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Actions\VerifyTelegramConnection;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_rename_and_disable_a_connection_but_cannot_delete_its_files(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id]);

        $this->actingAs($connection->user)->patchJson('/telegram/connections/'.$connection->id, [
            'name' => 'Archive', 'enabled' => false,
        ])->assertOk()->assertJsonPath('connection.name', 'Archive')->assertJsonPath('connection.status', 'disabled');

        $this->deleteJson('/telegram/connections/'.$connection->id)->assertUnprocessable();
        $this->assertModelExists($connection);
    }

    public function test_owner_can_explicitly_remove_connection_file_records_including_trash_without_deleting_telegram_files(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $regular = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id]);
        $trashed = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id]);
        $trashed->delete();
        $other = File::factory()->uploaded()->create(['user_id' => $connection->user_id]);

        $this->actingAs($connection->user)->deleteJson('/telegram/connections/'.$connection->id, ['delete_files' => true])
            ->assertOk()->assertJsonPath('deleted', true);

        $this->assertModelMissing($connection);
        $this->assertModelMissing($regular);
        $this->assertModelMissing($trashed);
        $this->assertModelExists($other);
    }

    public function test_connection_deletion_with_active_transfer_is_rejected_even_with_file_removal_confirmation(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id]);
        FileOperation::factory()->create([
            'user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'file_id' => $file->id,
            'status' => 'pending',
        ]);

        $this->actingAs($connection->user)->deleteJson('/telegram/connections/'.$connection->id, ['delete_files' => true])
            ->assertUnprocessable();
        $this->assertModelExists($connection);
        $this->assertModelExists($file);
    }

    public function test_an_older_verification_result_cannot_replace_a_newer_requested_target(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot', 'last_verified_at' => null]);
        $originalChat = $connection->chat_id;
        Queue::fake([VerifyTelegramConnectionJob::class]);
        $verifyCount = 0;
        $callback = function () use ($connection, &$verifyCount): ConnectionVerification {
            if ($verifyCount++ === 0) {
                $this->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@new_target'])->assertAccepted();

                return new ConnectionVerification(ok: true, chatId: 111);
            }

            return new ConnectionVerification(ok: true, chatId: 222);
        };
        $driver = new class($callback) extends FakeTelegramStorageDriver
        {
            public function __construct(private readonly \Closure $callback) {}

            public function verify(TelegramConnection $connection): ConnectionVerification
            {
                return ($this->callback)();
            }
        };
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly TelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@old_target'])->assertAccepted();
        $first = Queue::pushed(VerifyTelegramConnectionJob::class)->first();
        $first->handle(app(VerifyTelegramConnection::class));
        $this->assertSame($originalChat, $connection->fresh()->chat_id);
        $this->assertSame('reconnecting', $connection->fresh()->status->value);
        $second = Queue::pushed(VerifyTelegramConnectionJob::class)->last();
        $second->handle(app(VerifyTelegramConnection::class));
        $this->assertSame(222, $connection->fresh()->chat_id);
        $this->assertSame('connected', $connection->fresh()->status->value);
    }
}
