<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Actions\ProcessPersonalTelegramLogin;
use App\Domains\Telegram\Actions\RevokePersonalTelegramConnection;
use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\ProcessPersonalTelegramLoginJob;
use App\Domains\Telegram\Jobs\RevokePersonalTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramLoginException;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PersonalTelegramConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_start_a_personal_connection_with_encrypted_phone_and_id_only_job(): void
    {
        $owner = User::factory()->create();
        Queue::fake();

        $response = $this->actingAs($owner)->postJson('/telegram/connections/personal', [
            'name' => 'Personal archive',
            'phone' => '+15551234567',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('connection.method', 'personal')
            ->assertJsonPath('connection.status', 'connecting')
            ->assertJsonMissingPath('phone')
            ->assertJsonMissingPath('session')
            ->assertJsonMissingPath('credentials');

        $connection = TelegramConnection::query()->findOrFail($response->json('connection.id'));
        $account = TelegramAccount::query()->findOrFail($connection->telegram_account_id);

        $this->assertSame($owner->id, $connection->user_id);
        $this->assertSame($owner->id, $account->user_id);
        $this->assertSame('personal', $connection->method->value);
        $this->assertStringContainsString('+15551234567', (string) $account->credentials);
        $this->assertStringNotContainsString('+15551234567', (string) DB::table('telegram_accounts')->where('id', $account->id)->value('credentials'));

        Queue::assertPushedOn('telegram', ProcessPersonalTelegramLoginJob::class, function (ProcessPersonalTelegramLoginJob $job) use ($account): bool {
            return $job->accountId === $account->id
                && ! str_contains(serialize($job), '+15551234567');
        });
    }

    public function test_owner_cannot_create_a_duplicate_active_personal_connection(): void
    {
        $owner = User::factory()->create();
        Queue::fake();

        $this->actingAs($owner)->postJson('/telegram/connections/personal', [
            'name' => 'First personal connection',
            'phone' => '+15551234567',
        ])->assertAccepted();

        $this->actingAs($owner)->postJson('/telegram/connections/personal', [
            'name' => 'Duplicate personal connection',
            'phone' => '+15557654321',
        ])->assertConflict();

        $this->assertSame(1, TelegramConnection::query()->where('user_id', $owner->id)->where('method', 'personal')->count());
    }

    public function test_only_the_owner_can_submit_a_personal_login_code(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'awaiting_code']);
        Queue::fake();

        $this->actingAs($other)->postJson('/telegram/connections/'.$connection->id.'/personal-login/code', ['code' => '12345'])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_submitted_login_code_is_encrypted_and_only_the_account_id_is_queued(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'awaiting_code']);
        Queue::fake();

        $this->actingAs($owner)->postJson('/telegram/connections/'.$connection->id.'/personal-login/code', ['code' => '12345'])
            ->assertAccepted();

        $account = $connection->account()->firstOrFail();
        $this->assertStringContainsString('12345', (string) $account->credentials);
        $this->assertStringNotContainsString('12345', (string) DB::table('telegram_accounts')->where('id', $account->id)->value('credentials'));
        Queue::assertPushedOn('telegram', ProcessPersonalTelegramLoginJob::class, function (ProcessPersonalTelegramLoginJob $job) use ($account): bool {
            return $job->accountId === $account->id && ! str_contains(serialize($job), '12345');
        });
    }

    public function test_login_code_challenge_transitions_to_optional_two_factor_and_clears_code(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'checking_code'], credentials: ['code' => '12345']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)
            ->shouldReceive('completeCode')
            ->once()
            ->withArgs(fn (TelegramAccount $receivedAccount, string $code): bool => $receivedAccount->id === $account->id && $code === '12345')
            ->andReturn(PersonalTelegramLoginOutcome::PasswordRequired);

        app(ProcessPersonalTelegramLogin::class)->handle($account->id);

        $this->assertNull($account->fresh()->credentials);
        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);
        $this->assertSame('awaiting_password', $connection->fresh()->metadata['personal_login_step']);
    }

    public function test_invalid_code_is_reported_without_retaining_or_exposing_the_code(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'checking_code'], credentials: ['code' => '12345']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)
            ->shouldReceive('completeCode')
            ->once()
            ->andThrow(new PersonalTelegramLoginException('invalid_code'));

        app(ProcessPersonalTelegramLogin::class)->handle($account->id);

        $connection = $connection->fresh();
        $this->assertNull($account->fresh()->credentials);
        $this->assertSame('awaiting_code', $connection->metadata['personal_login_step']);
        $this->assertSame('That Telegram code is incorrect. Try again.', $connection->last_error);
        $this->assertStringNotContainsString('12345', $connection->last_error);
    }

    public function test_expired_code_requires_a_new_phone_request(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'checking_code'], credentials: ['code' => '12345']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)
            ->shouldReceive('completeCode')
            ->once()
            ->andThrow(new PersonalTelegramLoginException('expired_code'));

        app(ProcessPersonalTelegramLogin::class)->handle($account->id);

        $connection = $connection->fresh();
        $this->assertNull($account->fresh()->credentials);
        $this->assertSame(TelegramConnectionStatus::Error, $connection->status);
        $this->assertSame('awaiting_phone', $connection->metadata['personal_login_step']);
        $this->assertSame('That Telegram code expired. Request a new code.', $connection->last_error);
    }

    public function test_invalid_two_factor_password_can_be_retried_without_retaining_the_password(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'checking_password'], credentials: ['password' => 'correct horse battery staple']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)
            ->shouldReceive('completePassword')
            ->once()
            ->andThrow(new PersonalTelegramLoginException('invalid_password'));

        app(ProcessPersonalTelegramLogin::class)->handle($account->id);

        $connection = $connection->fresh();
        $this->assertNull($account->fresh()->credentials);
        $this->assertSame('awaiting_password', $connection->metadata['personal_login_step']);
        $this->assertSame('That two-step verification password is incorrect. Try again.', $connection->last_error);
    }

    public function test_successful_login_clears_temporary_password_and_marks_the_account_connected(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'checking_password'], credentials: ['password' => 'a secret']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)->shouldReceive('completePassword')->once();

        app(ProcessPersonalTelegramLogin::class)->handle($account->id);

        $this->assertNull($account->fresh()->credentials);
        $this->assertSame(TelegramAccountStatus::Connected, $account->fresh()->status);
        $this->assertSame(TelegramConnectionStatus::Connected, $connection->fresh()->status);
        $this->assertSame('connected', $connection->fresh()->metadata['personal_login_step']);
    }

    public function test_temporary_worker_failure_releases_the_job_and_keeps_the_encrypted_input_for_retry(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'requesting_code'], credentials: ['phone' => '+15551234567']);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)
            ->shouldReceive('requestCode')
            ->once()
            ->andThrow(new PersonalTelegramLoginException('temporary', true, 7));
        $job = (new ProcessPersonalTelegramLoginJob($account->id))->withFakeQueueInteractions();

        $job->handle(app(ProcessPersonalTelegramLogin::class));
        $job->assertReleased(7);

        $this->assertStringContainsString('+15551234567', (string) $account->fresh()->credentials);
        $this->assertSame('requesting_code', $connection->fresh()->metadata['personal_login_step']);
    }

    public function test_personal_sessions_are_separated_by_owner_and_account(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $firstAccount = TelegramAccount::factory()->forUser($firstOwner)->create();
        $secondAccount = TelegramAccount::factory()->forUser($secondOwner)->create();
        $sessions = new class implements PersonalTelegramSessionClient
        {
            /** @var list<string> */
            public array $sessionNames = [];

            public function requestCode(string $sessionName, string $phone): void
            {
                $this->sessionNames[] = $sessionName;
            }

            public function completeCode(string $sessionName, string $code): PersonalTelegramLoginOutcome
            {
                $this->sessionNames[] = $sessionName;

                return PersonalTelegramLoginOutcome::Connected;
            }

            public function completePassword(string $sessionName, string $password): void
            {
                $this->sessionNames[] = $sessionName;
            }

            public function revoke(string $sessionName): void
            {
                $this->sessionNames[] = $sessionName;
            }

            public function upload(string $sessionName, PersonalTelegramUploadData $data, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
            {
                throw new \LogicException('Personal session upload is not part of this login test.');
            }

            public function download(string $sessionName, int $messageId, string $destinationPath): void
            {
                throw new \LogicException('Personal session download is not part of this login test.');
            }

            public function delete(string $sessionName, int $messageId): void
            {
                throw new \LogicException('Personal session delete is not part of this login test.');
            }

            public function exists(string $sessionName, int $messageId): bool
            {
                throw new \LogicException('Personal session existence checks are not part of this login test.');
            }

            public function verify(string $sessionName): void
            {
                throw new \LogicException('Personal session verification is not part of this login test.');
            }
        };
        $runtime = new PersonalTelegramRuntimeManager($sessions);

        $runtime->requestCode($firstAccount, '+15551234567');
        $runtime->requestCode($secondAccount, '+15551234567');

        $this->assertNotSame($sessions->sessionNames[0], $sessions->sessionNames[1]);
        $this->assertStringNotContainsString('/'.$firstOwner->id.'/', $sessions->sessionNames[0]);
        $this->assertStringNotContainsString('/'.$secondOwner->id.'/', $sessions->sessionNames[1]);
    }

    public function test_reconnect_reuses_the_existing_owner_session_and_only_queues_its_id(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'error'], status: TelegramConnectionStatus::Error);
        Queue::fake();

        $this->actingAs($owner)->postJson('/telegram/connections/'.$connection->id.'/personal-reconnect', ['phone' => '+15551234567'])
            ->assertAccepted();

        $connection = $connection->fresh();
        $this->assertSame(TelegramConnectionStatus::Connecting, $connection->status);
        $this->assertSame(1, TelegramConnection::query()->where('user_id', $owner->id)->where('method', 'personal')->count());
        Queue::assertPushedOn('telegram', ProcessPersonalTelegramLoginJob::class, fn (ProcessPersonalTelegramLoginJob $job): bool => $job->accountId === $connection->telegram_account_id);
    }

    public function test_revoke_is_worker_owned_and_keeps_the_account_until_session_logout_finishes(): void
    {
        $owner = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'connected'], status: TelegramConnectionStatus::Connected);
        $account = $connection->account()->firstOrFail();
        Queue::fake();

        $this->actingAs($owner)->deleteJson('/telegram/connections/'.$connection->id)->assertAccepted();

        $this->assertNotNull($connection->fresh());
        $this->assertNotNull($account->fresh());
        $this->assertNull($account->fresh()->credentials);
        $this->assertSame('revoking', $connection->fresh()->metadata['personal_login_step']);
        Queue::assertPushed(RevokePersonalTelegramConnectionJob::class, function (RevokePersonalTelegramConnectionJob $job) use ($account, $connection): bool {
            return $job->accountId === $account->id && $job->connectionId === $connection->id
                && ! str_contains(serialize($job), '+15551234567');
        });
    }

    public function test_revoke_removes_only_the_owner_personal_connection_after_worker_logout(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->personalConnection($owner, ['personal_login_step' => 'revoking'], status: TelegramConnectionStatus::Disabled);
        $otherConnection = $this->personalConnection($other, ['personal_login_step' => 'connected'], status: TelegramConnectionStatus::Connected);
        $account = $connection->account()->firstOrFail();
        $this->mock(PersonalTelegramRuntimeManager::class)->shouldReceive('revoke')->once()->withArgs(fn (TelegramAccount $received): bool => $received->id === $account->id);

        (new RevokePersonalTelegramConnection($this->app->make(PersonalTelegramRuntimeManager::class)))
            ->handle($connection->id, $account->id);

        $this->assertDatabaseMissing('telegram_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('telegram_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('telegram_connections', ['id' => $otherConnection->id]);
    }

    private function personalConnection(User $owner, array $metadata, array $credentials = [], TelegramConnectionStatus $status = TelegramConnectionStatus::Pending): TelegramConnection
    {
        $account = TelegramAccount::factory()->forUser($owner)->create([
            'method' => 'personal',
            'status' => TelegramAccountStatus::Reconnecting,
            'credentials' => $credentials === [] ? null : json_encode($credentials, JSON_THROW_ON_ERROR),
        ]);

        return TelegramConnection::factory()->create([
            'user_id' => $owner->id,
            'telegram_account_id' => $account->id,
            'method' => 'personal',
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }
}
