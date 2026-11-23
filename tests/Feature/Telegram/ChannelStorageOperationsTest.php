<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Telegram\Drivers\RoxiTelegramStorageDriver;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ChannelStorageOperationsTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([-1000000123456, 'channels'])]
    #[TestWith([-123456, 'messages'])]
    #[TestWith([123456, 'messages'])]
    public function test_download_existence_and_deletion_use_the_files_original_chat(int $chatId, string $namespace): void
    {
        $file = File::factory()->uploaded()->create(['telegram_chat_id' => $chatId, 'telegram_message_id' => 901]);
        $runtime = $this->partialMock(RoxiRuntime::class);
        $readArgs = $namespace === 'channels' ? ['channel' => $chatId, 'id' => [901]] : ['id' => [901]];
        $deleteArgs = $namespace === 'channels' ? ['channel' => $chatId, 'id' => [901]] : ['id' => [901], 'revoke' => true];
        $runtime->shouldReceive('methodCallAsyncRead')->with($namespace.'.getMessages', $readArgs)->twice()
            ->andReturn(['messages' => [['_' => 'message', 'id' => 901, 'media' => ['_' => 'messageMediaDocument']]]]);
        $runtime->shouldReceive('downloadToFile')->with(['_' => 'messageMediaDocument'], '/tmp/roxifile-test-download')->once();
        $runtime->shouldReceive('methodCallAsyncRead')->with($namespace.'.deleteMessages', $deleteArgs)->once()->andReturn([]);
        $driver = app(RoxiTelegramStorageDriver::class);

        $this->assertTrue($driver->exists($file));
        $driver->download($file, '/tmp/roxifile-test-download');
        $driver->delete($file);
    }

    public function test_channel_message_empty_is_reported_as_missing(): void
    {
        $file = File::factory()->uploaded()->create(['telegram_chat_id' => -1000000123456, 'telegram_message_id' => 901]);
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getMessages', ['channel' => -1000000123456, 'id' => [901]])
            ->once()->andReturn(['messages' => [['_' => 'messageEmpty', 'id' => 901]]]);

        $this->assertFalse(app(RoxiTelegramStorageDriver::class)->exists($file));
    }
}
