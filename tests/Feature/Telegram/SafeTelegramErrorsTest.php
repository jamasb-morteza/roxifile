<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\WorkerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SafeTelegramErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_logs_redact_bot_tokens_codes_and_private_paths(): void
    {
        $user = User::factory()->create();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->once()->with('Telegram worker error', Mockery::on(
            fn (array $context): bool => ! str_contains($context['error'], '123456:secret-token')
                && ! str_contains($context['error'], 'ROXI-ABCD-EFGH'),
        ));
        $health = app(WorkerHealth::class);
        $health->heartbeat(false, false);

        $health->recordError(new \RuntimeException('network https://api.telegram.org/bot123456:secret-token/getFile ROXI-ABCD-EFGH /var/www/roxifile/storage/app/private/madeline/roxi'));

        $response = $this->actingAs($user)->getJson('/telegram/worker-status')->assertOk();
        $this->assertStringNotContainsString('123456:secret-token', $response->json('last_error'));
        $this->assertStringNotContainsString('ROXI-ABCD-EFGH', $response->json('last_error'));
        $this->assertStringNotContainsString('/var/www/', $response->json('last_error'));
    }

    public function test_network_failure_during_bot_creation_has_a_safe_response(): void
    {
        $token = '123456:secret-token';
        Http::fake([
            'api.telegram.org/bot'.$token.'/getMe' => static fn () => throw new ConnectionException('Failed URL https://api.telegram.org/bot'.$token.'/getMe'),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/telegram/connections/bot', [
            'name' => 'Bot', 'token' => $token,
        ])->assertStatus(503);

        $this->assertStringNotContainsString($token, $response->json('message'));
    }

    public function test_bot_rate_limits_and_server_errors_retry_but_rejected_credentials_do_not(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '123456:matrix-token']);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id, 'method' => 'bot',
        ]);
        Queue::fake([TelegramOperationJob::class]);
        $sequence = Http::fakeSequence('api.telegram.org/bot123456:matrix-token/sendDocument');
        foreach ([429, 500, 401, 403] as $status) {
            $sequence->push([
                'ok' => false, 'error_code' => $status, 'description' => 'Rejected bot123456:matrix-token',
                'parameters' => ['retry_after' => 27],
            ], $status);
        }
        foreach ([429 => 'failed', 500 => 'failed', 401 => 'failed', 403 => 'failed'] as $status => $expected) {
            $response = $this->actingAs($connection->user)->postJson('/files/upload', [
                'connection_id' => $connection->id,
                'file' => UploadedFile::fake()->createWithContent('matrix.txt', 'bytes'),
            ])->assertAccepted();
            $id = $response->json('operation_id');
            (new TelegramOperationJob($id))->handle(app(TelegramOperationProcessor::class));
            $operation = FileOperation::query()->findOrFail($id);
            $this->assertSame($expected, $operation->status->value);
            $this->assertStringNotContainsString('matrix-token', $operation->error);
            if ($status === 429) {
                $this->assertNull($operation->available_at, 'The expired upload body must not be retried without its bytes.');
                $this->assertStringContainsString('rate_limit', $operation->error);
            }
        }

        Queue::assertPushed(TelegramOperationJob::class, 4);
    }
}
