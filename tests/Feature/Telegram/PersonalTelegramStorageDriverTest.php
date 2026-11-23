<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Actions\ManageFileOperation;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Drivers\PersonalTelegramStorageDriver;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use App\Domains\Telegram\Runtime\PersonalTelegramSessionUnavailable;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakePersonalTelegramSessionClient;
use Tests\TestCase;

class PersonalTelegramStorageDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_returns_the_confirmed_message_and_preserves_ciphertext_through_the_worker(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'size' => strlen('ROXIF001ciphertext'),
            'encryption_header' => 'ROXIF001',
        ]);
        Storage::disk('local')->put('telegram-upload/personal-ciphertext.bin', 'ROXIF001ciphertext');
        $operation = $this->createOperation($file, FileOperationType::Upload, [
            'temp_path' => 'telegram-upload/personal-ciphertext.bin',
        ]);
        $client = $this->fakeClient();

        $this->runWorker($operation);

        $this->assertSame(FileOperationStatus::Completed, $operation->fresh()->status);
        $this->assertSame(FileStatus::Uploaded, $file->fresh()->status);
        $this->assertSame(45001, $file->fresh()->telegram_message_id);
        $this->assertSame('ROXIF001ciphertext', $client->messages[45001]);
        $this->assertSame(1, count($client->calls));
        $this->assertSame('upload', $client->calls[0]['method']);
        $this->assertSame($file->id, $client->calls[0]['file_id']);
        $this->assertInstanceOf(PersonalTelegramUploadData::class, $client->lastUploadData);
        $this->assertSame($operation->id, $client->lastUploadData->operationId);
        $this->assertSame($file->id, $client->lastUploadData->fileId);
        $this->assertSame($file->size, $client->lastUploadData->fileSize);
        $this->assertSame($file->mime_type, $client->lastUploadData->mimeType);
        $this->assertSame($file->name, $client->lastUploadData->fileName);
        $this->assertSame($operation->id, $client->lastUploadData->randomId);
        $this->assertSame('', $client->lastUploadData->caption);
        $this->assertSame(
            app(PersonalTelegramRuntimeManager::class)->sessionNameFor($account),
            $client->calls[0]['session'],
        );
    }

    public function test_plaintext_upload_data_keeps_the_original_random_id_and_caption(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'name' => 'quarterly-report.txt',
            'size' => strlen('plain payload'),
        ]);
        Storage::disk('local')->put('telegram-upload/plain-report.txt', 'plain payload');
        $operation = $this->createOperation($file, FileOperationType::Upload, [
            'random_id' => 731_009,
            'temp_path' => 'telegram-upload/plain-report.txt',
        ]);
        $client = $this->fakeClient();

        $this->runWorker($operation);

        $this->assertNotNull($client->lastUploadData);
        $this->assertSame(731_009, $client->lastUploadData->randomId);
        $this->assertStringContainsString('Path: quarterly-report.txt', $client->lastUploadData->caption);
    }

    public function test_download_writes_the_confirmed_bytes_and_completes_the_file_operation(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'status' => FileStatus::Uploaded,
            'telegram_message_id' => 51001,
        ]);
        $operation = $this->createOperation($file, FileOperationType::Download);
        $client = $this->fakeClient();
        $client->messages[51001] = 'ROXIF001ciphertext';

        $this->runWorker($operation);

        $operation = $operation->fresh();
        $this->assertSame(FileOperationStatus::Completed, $operation->status);
        $this->assertSame('ROXIF001ciphertext', Storage::disk('local')->get($operation->metadata['downloaded_path']));
        $this->assertSame('download', $client->calls[0]['method']);
        $this->assertSame(51001, $client->calls[0]['message_id']);
        $this->assertSame(
            app(PersonalTelegramRuntimeManager::class)->sessionNameFor($account),
            $client->calls[0]['session'],
        );
    }

    public function test_exists_uses_the_owners_session_to_confirm_a_remote_message(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'status' => FileStatus::Uploaded,
            'telegram_message_id' => 52001,
        ]);
        $client = $this->fakeClient();
        $client->messages[52001] = 'ciphertext';

        $exists = app(PersonalTelegramStorageDriver::class)->exists($file);

        $this->assertTrue($exists);
        $this->assertSame('exists', $client->calls[0]['method']);
        $this->assertSame(
            app(PersonalTelegramRuntimeManager::class)->sessionNameFor($account),
            $client->calls[0]['session'],
        );
    }

    public function test_exists_reports_a_message_missing_from_the_owners_session(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'status' => FileStatus::Uploaded,
            'telegram_message_id' => 52002,
        ]);
        $client = $this->fakeClient();

        $exists = app(PersonalTelegramStorageDriver::class)->exists($file);

        $this->assertFalse($exists);
        $this->assertSame('exists', $client->calls[0]['method']);
        $this->assertSame(52002, $client->calls[0]['message_id']);
    }

    public function test_permanent_delete_removes_the_remote_message_and_completes_the_file_operation(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, [
            'status' => FileStatus::Uploaded,
            'telegram_message_id' => 53001,
        ]);
        $file->delete();
        $operation = $this->createOperation($file, FileOperationType::Delete, ['permanent' => true]);
        $client = $this->fakeClient();
        $client->messages[53001] = 'ciphertext';

        $this->runWorker($operation);

        $this->assertSame(FileOperationStatus::Completed, $operation->fresh()->status);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertArrayNotHasKey(53001, $client->messages);
        $this->assertSame('delete', $client->calls[0]['method']);
        $this->assertSame(53001, $client->calls[0]['message_id']);
        $this->assertSame(
            app(PersonalTelegramRuntimeManager::class)->sessionNameFor($account),
            $client->calls[0]['session'],
        );
    }

    public function test_foreign_file_never_uses_the_personal_session_for_any_storage_operation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($otherUser, $account, $connection, [
            'status' => FileStatus::Uploaded,
            'telegram_message_id' => 54001,
        ]);
        $operation = $this->createOperation($file, FileOperationType::Upload);
        Storage::disk('local')->put('telegram-upload/foreign.bin', 'ciphertext');
        $content = TelegramUploadContent::fromPath(Storage::disk('local')->path('telegram-upload/foreign.bin'), strlen('ciphertext'));
        $client = $this->fakeClient();
        $driver = app(PersonalTelegramStorageDriver::class);

        $calls = [
            fn () => $driver->upload($operation, $file, $content, static function (int $bytes, int $total): void {}),
            fn () => $driver->download($file, Storage::disk('local')->path('telegram-download/foreign')),
            fn () => $driver->delete($file),
            fn () => $driver->exists($file),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A foreign file used the personal Telegram session.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'The personal Telegram session is unavailable. Reconnect the connection and retry this operation.',
                    $exception->getMessage(),
                );
            }
        }

        $content->close();
        $this->assertSame([], $client->calls);
    }

    public function test_missing_session_failure_stays_visible_and_the_operation_can_be_retried(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, ['size' => strlen('retryable-ciphertext')]);
        Storage::disk('local')->put('telegram-upload/retryable.bin', 'retryable-ciphertext');
        $operation = $this->createOperation($file, FileOperationType::Upload, ['temp_path' => 'telegram-upload/retryable.bin']);
        $client = $this->fakeClient();
        $client->throwOnNextStorageCall = new PersonalTelegramSessionUnavailable(
            new \RuntimeException('auth_key=personal-session-secret'),
        );

        $this->runWorker($operation);

        $operation = $operation->fresh();
        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertSame(
            '[personal_session_unavailable] The personal Telegram session is unavailable. Reconnect the connection and retry this operation.',
            $operation->error,
        );
        $this->assertStringNotContainsString('personal-session-secret', $operation->error);
        $this->assertSame(FileStatus::Failed, $file->fresh()->status);
        Storage::disk('local')->assertExists('telegram-upload/retryable.bin');

        Queue::fake([TelegramOperationJob::class]);
        app(ManageFileOperation::class)->retry($operation);

        Queue::assertPushed(TelegramOperationJob::class, fn (TelegramOperationJob $job): bool => $job->operationId === $operation->id);
        $this->assertSame(FileOperationStatus::Pending, $operation->fresh()->status);
        $this->runWorker($operation->fresh());

        $this->assertSame(FileOperationStatus::Completed, $operation->fresh()->status);
        $this->assertSame('retryable-ciphertext', $client->messages[45001]);
        Storage::disk('local')->assertMissing('telegram-upload/retryable.bin');
    }

    public function test_duplicate_worker_delivery_does_not_upload_a_personal_file_twice(): void
    {
        $owner = User::factory()->create();
        [$account, $connection] = $this->connectedPersonalConnection($owner);
        $file = $this->createFile($owner, $account, $connection, ['size' => strlen('one-copy')]);
        Storage::disk('local')->put('telegram-upload/one-copy.bin', 'one-copy');
        $operation = $this->createOperation($file, FileOperationType::Upload, ['temp_path' => 'telegram-upload/one-copy.bin']);
        $client = $this->fakeClient();
        $client->duringUpload = function () use ($operation): void {
            $this->runWorker($operation);
        };

        $this->runWorker($operation);

        $this->assertSame(FileOperationStatus::Completed, $operation->fresh()->status);
        $this->assertSame(1, count($client->calls));
        $this->assertCount(1, $client->messages);
    }

    /** @return array{TelegramAccount, TelegramConnection} */
    private function connectedPersonalConnection(User $owner): array
    {
        $account = TelegramAccount::factory()->forUser($owner)->create([
            'method' => TelegramMethod::Personal,
            'status' => TelegramAccountStatus::Connected,
        ]);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $owner->id,
            'telegram_account_id' => $account->id,
            'method' => TelegramMethod::Personal,
            'status' => TelegramConnectionStatus::Connected,
            'chat_id' => null,
        ]);

        return [$account, $connection];
    }

    /** @param array<string, mixed> $attributes */
    private function createFile(User $owner, TelegramAccount $account, TelegramConnection $connection, array $attributes = []): File
    {
        return File::factory()->create(array_merge([
            'user_id' => $owner->id,
            'telegram_account_id' => $account->id,
            'telegram_connection_id' => $connection->id,
        ], $attributes));
    }

    /** @param array<string, mixed> $metadata */
    private function createOperation(File $file, FileOperationType $type, array $metadata = []): FileOperation
    {
        return FileOperation::factory()->create([
            'user_id' => $file->user_id,
            'file_id' => $file->id,
            'telegram_account_id' => $file->telegram_account_id,
            'telegram_connection_id' => $file->telegram_connection_id,
            'operation' => $type,
            'status' => FileOperationStatus::Pending,
            'metadata' => $metadata,
        ]);
    }

    private function fakeClient(): FakePersonalTelegramSessionClient
    {
        $client = new FakePersonalTelegramSessionClient;
        $this->app->instance(PersonalTelegramSessionClient::class, $client);
        $this->app->forgetInstance(PersonalTelegramRuntimeManager::class);
        $this->app->forgetInstance(StorageManager::class);

        return $client;
    }

    private function runWorker(FileOperation $operation): void
    {
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
    }
}
