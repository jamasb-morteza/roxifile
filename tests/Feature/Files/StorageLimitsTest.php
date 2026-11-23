<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorageLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_respects_owned_storage_usage_and_pending_reservations(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        File::factory()->uploaded()->create(['user_id' => $user->id, 'size' => 90]);
        config(['telegram.storage.max_user_bytes' => 100]);
        Queue::fake([TelegramOperationJob::class]);

        $this->actingAs($user)->post('/files/upload', [
            'file' => UploadedFile::fake()->createWithContent('first.txt', '123456'),
            'connection_id' => $connection->id,
        ])->assertAccepted();
        $this->post('/files/upload', [
            'file' => UploadedFile::fake()->createWithContent('second.txt', '123456'),
            'connection_id' => $connection->id,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(2, File::where('user_id', $user->id)->count());
        Queue::assertPushed(TelegramOperationJob::class, 1);
    }
}
