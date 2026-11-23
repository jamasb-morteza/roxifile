<?php

namespace App\Domains\Files\Events;

use App\Domains\Files\Models\FileOperation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

abstract class FileOperationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $connection = 'redis';

    /** @var array{operation_id: int, file_id: int|null, status: string, progress: int} */
    private readonly array $payload;

    public string $broadcastQueue = 'telegram';

    private readonly int $userId;

    private readonly string $operationType;

    public function __construct(FileOperation $operation)
    {
        $this->userId = $operation->user_id;
        $this->operationType = $operation->operation->value;
        $this->payload = [
            'operation_id' => $operation->id, 'file_id' => $operation->file_id,
            'status' => $operation->status->value, 'progress' => $operation->progress,
        ];
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return sprintf('file.%s.%s', $this->operationType, $this->suffix());
    }

    abstract protected function suffix(): string;

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
