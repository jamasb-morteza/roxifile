<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Files\Models\FileOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationLease
{
    /** @var array<int, string> */
    private array $tokens = [];

    public function acquire(int $id): bool
    {
        $token = (string) Str::ulid();
        $claimed = FileOperation::query()->whereKey($id)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled', 'needs_review'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['lease_token' => $token, 'lease_expires_at' => now()->addSeconds($this->ttl())]);

        if ($claimed !== 1) {
            return false;
        }

        $this->tokens[$id] = $token;

        return true;
    }

    public function owns(int $id): bool
    {
        return isset($this->tokens[$id]) && FileOperation::query()->whereKey($id)
            ->where('lease_token', $this->tokens[$id])->where('lease_expires_at', '>', now())
            ->where('status', '!=', 'cancelled')->exists();
    }

    public function assertOwned(int $id): void
    {
        if (! $this->owns($id)) {
            throw new \RuntimeException('Operation execution lease was lost.');
        }
    }

    public function throwIfCancelled(int $id): void
    {
        $this->assertOwned($id);
        if (! empty(FileOperation::query()->findOrFail($id)->metadata['cancel_requested_at'])) {
            throw new \DomainException('Transfer cancellation requested.');
        }
    }

    /** @param callable(FileOperation): void $update */
    public function mutate(int $id, callable $update): FileOperation
    {
        return DB::transaction(function () use ($id, $update): FileOperation {
            $operation = FileOperation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($id);
            $update($operation);

            return $operation;
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateMetadata(int $id, array $changes): FileOperation
    {
        return $this->mutate($id, function (FileOperation $operation) use ($changes): void {
            $operation->forceFill(['metadata' => array_merge($operation->metadata ?? [], $changes)])->save();
        });
    }

    public function beginSend(int $id): FileOperation
    {
        return $this->mutate($id, function (FileOperation $operation): void {
            if (! empty($operation->metadata['cancel_requested_at'])) {
                throw new \DomainException('Transfer cancellation requested.');
            }
            $operation->forceFill(['metadata' => array_merge($operation->metadata ?? [], ['send_started_at' => now()->toIso8601String()])])->save();
        });
    }

    public function refresh(): bool
    {
        foreach ($this->tokens as $id => $token) {
            $updated = FileOperation::query()->whereKey($id)->where('lease_token', $token)
                ->where('lease_expires_at', '>', now())
                ->update(['lease_expires_at' => now()->addSeconds($this->ttl())]);

            if ($updated !== 1) {
                return false;
            }
        }

        return true;
    }

    public function invalidate(): void
    {
        $this->tokens = [];
    }

    public function release(int $id): void
    {
        if (isset($this->tokens[$id])) {
            FileOperation::query()->whereKey($id)->where('lease_token', $this->tokens[$id])
                ->update(['lease_token' => null, 'lease_expires_at' => null]);
            unset($this->tokens[$id]);
        }
    }

    private function ttl(): int
    {
        return max(30, (int) config('telegram.worker.operation_lease_seconds', 120));
    }
}
