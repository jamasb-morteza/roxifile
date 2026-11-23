<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramStreamDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_stream_a_ranged_download_from_the_worker(): void
    {
        $file = $this->storedFile();
        $forwardedRange = null;
        Http::preventStrayRequests();
        Http::fake([
            $this->workerContentUrl($file) => function (Request $request) use (&$forwardedRange) {
                $forwardedRange = $request->header('Range')[0] ?? null;

                return Http::response('456', 206, [
                    'Accept-Ranges' => 'bytes',
                    'Content-Length' => '3',
                    'Content-Range' => 'bytes 4-6/10',
                    'Content-Type' => 'video/mp4',
                ]);
            },
        ]);

        $response = $this->actingAs($file->user)
            ->withHeader('Range', 'bytes=4-6')
            ->get("/files/{$file->id}/stream-download");
        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 4-6/10');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('456', $response->streamedContent());
        $this->assertSame('bytes=4-6', $forwardedRange);
        Http::assertSent(fn (Request $request): bool => is_string($request->header('X-Roxi-Download-Capability')[0] ?? null));
    }

    public function test_head_stream_requests_are_forwarded_to_the_worker(): void
    {
        $file = $this->storedFile();
        $forwardedMethod = null;
        Http::fake([
            $this->workerContentUrl($file) => function (Request $request) use (&$forwardedMethod) {
                $forwardedMethod = $request->method();

                return Http::response('', 200, ['Content-Length' => '10']);
            },
        ]);

        $this->actingAs($file->user)
            ->call('HEAD', "/files/{$file->id}/stream-download")
            ->assertOk()
            ->assertHeader('Content-Length', '10');

        $this->assertSame('HEAD', $forwardedMethod);
    }

    public function test_proxy_preserves_an_unsatisfiable_range_response_from_the_worker(): void
    {
        $file = $this->storedFile();
        Http::preventStrayRequests();
        Http::fake([
            $this->workerContentUrl($file) => Http::response('', 416, [
                'Content-Range' => 'bytes */10',
            ]),
        ]);

        $this->actingAs($file->user)
            ->withHeader('Range', 'bytes=10-')
            ->get("/files/{$file->id}/stream-download")
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_if_range_requests_receive_a_complete_response_without_range_headers(): void
    {
        $file = $this->storedFile();
        $forwardedRange = null;
        $forwardedIfRange = null;
        Http::preventStrayRequests();
        Http::fake([
            $this->workerContentUrl($file) => function (Request $request) use (&$forwardedIfRange, &$forwardedRange) {
                $forwardedRange = $request->header('Range')[0] ?? null;
                $forwardedIfRange = $request->header('If-Range')[0] ?? null;

                return Http::response('0123456789', 200, [
                    'Content-Length' => '10',
                    'Content-Type' => 'video/mp4',
                ]);
            },
        ]);

        $response = $this->actingAs($file->user)
            ->withHeaders([
                'If-Range' => '"stale-validator"',
                'Range' => 'bytes=4-6',
            ])
            ->get("/files/{$file->id}/stream-download");

        $response->assertOk();
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('0123456789', $response->streamedContent());
        $this->assertNull($forwardedRange);
        $this->assertNull($forwardedIfRange);
    }

    public function test_anonymous_users_are_redirected_from_stream_download(): void
    {
        $file = $this->storedFile();
        Http::preventStrayRequests();

        $this->get("/files/{$file->id}/stream-download")
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_another_user_cannot_stream_a_file_from_the_worker(): void
    {
        $file = $this->storedFile();
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->get("/files/{$file->id}/stream-download")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_worker_failure_returns_a_recoverable_gateway_response(): void
    {
        $file = $this->storedFile();
        Http::preventStrayRequests();
        Http::fake([
            $this->workerContentUrl($file) => Http::response('', 503),
        ]);

        $this->actingAs($file->user)
            ->get("/files/{$file->id}/stream-download")
            ->assertStatus(502)
            ->assertJsonPath('message', 'The Telegram worker could not stream this file. Retry the download.');
    }

    public function test_bot_and_personal_methods_keep_the_prepared_download_fallback(): void
    {
        Http::preventStrayRequests();
        Queue::fake([TelegramOperationJob::class]);

        foreach ([TelegramMethod::Bot, TelegramMethod::Personal] as $method) {
            $file = $this->storedFile(method: $method);

            $this->actingAs($file->user)
                ->get("/files/{$file->id}/stream-download")
                ->assertStatus(501);
            $this->postJson("/files/{$file->id}/prepare-download")->assertAccepted();
        }

        Queue::assertPushed(TelegramOperationJob::class, 2);
        Http::assertNothingSent();
    }

    public function test_files_above_the_mtproto_stream_limit_receive_a_clear_size_error(): void
    {
        $file = $this->storedFile(['size' => TelegramDownloadStreamToken::MAX_FILE_BYTES + 1]);
        Http::preventStrayRequests();

        $this->actingAs($file->user)
            ->getJson("/files/{$file->id}/stream-download")
            ->assertStatus(413)
            ->assertJsonPath('message', 'MTProto streaming supports files up to 4,194,304,000 bytes. This file is outside that limit.');

        Http::assertNothingSent();
    }

    public function test_encrypted_stream_is_returned_as_ciphertext(): void
    {
        $file = $this->storedFile(['encryption_header' => 'ROXIF001']);
        $ciphertext = "ROXIF001\x00ciphertext-payload";
        Http::preventStrayRequests();
        Http::fake([
            $this->workerContentUrl($file) => Http::response($ciphertext, 200, [
                'Content-Length' => (string) strlen($ciphertext),
                'Content-Type' => 'application/octet-stream',
            ]),
        ]);

        $response = $this->actingAs($file->user)->get("/files/{$file->id}/stream-download");

        $response->assertOk();
        $this->assertSame($ciphertext, $response->streamedContent());
    }

    /** @param array<string, mixed> $attributes */
    private function storedFile(array $attributes = [], TelegramMethod $method = TelegramMethod::Roxi): File
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => $method]);

        return File::factory()->uploaded()->create(array_merge([
            'user_id' => $connection->user_id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'size' => 10,
            'mime_type' => 'video/mp4',
        ], $attributes));
    }

    private function workerContentUrl(File $file): string
    {
        return rtrim((string) config('telegram.worker.upload_bridge_url'), '/').'/files/'.$file->id.'/content';
    }
}
