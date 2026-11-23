<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Services\TelegramTempStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TempCleanupTest extends TestCase
{
    use RefreshDatabase;

    private TelegramTempStorage $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempStorage = new TelegramTempStorage;
    }

    private function makeTempFile(string $name): string
    {
        $path = 'telegram-upload/'.$name;

        Storage::disk('local')->put($path, 'temp-content-'.$name);

        return $path;
    }

    public function test_active_operation_temp_files_are_never_swept(): void
    {
        $path = $this->makeTempFile('active.bin');

        FileOperation::factory()->create([
            'status' => FileOperationStatus::Processing,
            'metadata' => ['temp_path' => $path],
        ]);

        $this->artisan('telegram:cleanup-uploads')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_failed_operation_temp_files_are_removed_after_grace_period(): void
    {
        $path = $this->makeTempFile('failed-old.bin');

        FileOperation::factory()->create([
            'status' => FileOperationStatus::Failed,
            'completed_at' => now()->subHours(2),
            'metadata' => ['temp_path' => $path],
        ]);

        // Fresh file: the general age cutoff (24h) would keep it, but the
        // failed-op grace period (completed_at + 1h, spec §47) releases it.
        $this->artisan('telegram:cleanup-uploads')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_failed_operation_temp_files_inside_the_grace_period_are_kept(): void
    {
        $path = $this->makeTempFile('failed-fresh.bin');

        FileOperation::factory()->create([
            'status' => FileOperationStatus::Failed,
            'completed_at' => now()->subMinutes(30),
            'metadata' => ['temp_path' => $path],
        ]);

        $this->artisan('telegram:cleanup-uploads')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_abandoned_temp_files_past_max_age_are_swept(): void
    {
        $path = $this->makeTempFile('abandoned.bin');

        // Age the file past the 24h cutoff.
        Storage::disk('local')->assertExists($path);
        touch(
            Storage::disk('local')->path($path),
            now()->subHours(30)->getTimestamp(),
        );

        $this->artisan('telegram:cleanup-uploads')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_expired_download_cache_releases_its_temporary_quota_and_keeps_recent_content(): void
    {
        $expiredPath = 'telegram-download/expired';
        $recentPath = 'telegram-download/recent';
        Storage::disk('local')->put($expiredPath, 'old');
        Storage::disk('local')->put($recentPath, 'new');
        $expired = FileOperation::factory()->create([
            'operation' => 'download', 'status' => 'completed', 'reserved_temp_bytes' => 3,
            'metadata' => ['downloaded_path' => $expiredPath, 'expires_at' => now()->subMinute()->toIso8601String()],
        ]);
        FileOperation::factory()->create([
            'operation' => 'download', 'status' => 'completed', 'reserved_temp_bytes' => 3,
            'metadata' => ['downloaded_path' => $recentPath, 'expires_at' => now()->addMinutes(15)->toIso8601String()],
        ]);

        $this->artisan('telegram:cleanup-uploads')->assertSuccessful();

        Storage::disk('local')->assertMissing($expiredPath);
        Storage::disk('local')->assertExists($recentPath);
        $this->assertSame(0, $expired->refresh()->reserved_temp_bytes);
    }
}
