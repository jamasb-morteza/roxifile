<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Actions\SetupRoxiStorageChat;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Connection('redis')]
#[Queue('telegram')]
class RunRoxiStorageChatSetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $connectionId, public readonly string $requestId) {}

    public function handle(SetupRoxiStorageChat $action): void
    {
        $lock = Cache::lock('telegram:chat-setup:'.$this->connectionId, 600);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $action->handle($this->connectionId, $this->requestId);
        } catch (RuntimeNotAvailable) {
            $this->release(10);
        } catch (Throwable $error) {
            $action->fail($this->connectionId, $this->requestId, $error);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $error): void
    {
        app(SetupRoxiStorageChat::class)->fail($this->connectionId, $this->requestId, $error);
    }
}
