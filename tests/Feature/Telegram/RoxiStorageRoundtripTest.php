<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoxiStorageRoundtripTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([123456, 'private'])]
    #[TestWith([-1000000123456, 'channel'])]
    public function test_uploaded_bytes_can_be_downloaded_through_the_live_runtime_adapter(int $chatId, string $chatType): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => 'invite', 'chat_id' => $chatId, 'chat_type' => $chatType]);
        $bytes = 'roundtrip payload';
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('uploadFromCallable')->once()->andReturnUsing(
            function (callable $read, int $size, string $mime, string $name, callable $cb, bool $seekable) use ($bytes): array {
                $this->assertSame($bytes, $read(0, $size));
                $cb(50.0);

                return ['_' => 'inputFile', 'id' => 10, 'parts' => 1, 'name' => $name, 'md5_checksum' => md5($bytes)];
            },
        );
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMedia', Mockery::on(
            fn (array $args): bool => $args['peer'] === $connection->chat_id && $args['media']['attributes'][0]['file_name'] === 'payload.txt',
        ))->once()->andReturn(['_' => 'updates', 'updates' => [
            ['_' => 'updateMessageID', 'id' => 901],
            ['_' => $chatType === 'channel' ? 'updateNewChannelMessage' : 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 901, 'media' => ['_' => 'messageMediaDocument']]],
        ]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with($chatType === 'channel' ? 'channels.getMessages' : 'messages.getMessages', $chatType === 'channel' ? ['channel' => $chatId, 'id' => [901]] : ['id' => [901]])
            ->once()->andReturn(['messages' => [['_' => 'message', 'id' => 901, 'media' => ['_' => 'messageMediaDocument']]]]);
        $runtime->shouldReceive('downloadToFile')->once()->andReturnUsing(
            static function (array $media, string $path) use ($bytes): void {
                file_put_contents($path, $bytes);
            },
        );

        $response = $this->actingAs($user)->post('/files/upload', [
            'file' => UploadedFile::fake()->createWithContent('payload.txt', $bytes),
            'connection_id' => $connection->id,
        ])->assertAccepted();

        $file = File::findOrFail($response->json('file_id'));
        $this->assertSame($connection->id, $file->telegram_connection_id);
        $this->assertSame($connection->telegram_account_id, $file->telegram_account_id);
        $this->assertSame(901, $file->telegram_message_id);
        $this->assertSame($chatId, $file->telegram_chat_id);
        $this->assertSame(hash('sha256', $bytes), $file->checksum);
        $this->postJson('/files/'.$file->id.'/prepare-download')->assertAccepted();
        $download = $this->get('/files/'.$file->id.'/download')->assertOk();
        $this->assertSame($bytes, $download->streamedContent());
    }
}
