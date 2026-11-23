<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Runtime\RoxiLoginException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoxiWebLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_the_configured_roxi_username_in_telegram_settings(): void
    {
        config()->set('telegram.roxi.username', '@RoxiSupport');
        $admin = User::factory()->superAdmin()->create();

        $this->withoutVite()
            ->actingAs($admin)
            ->get('/settings/telegram')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Telegram')
                ->where('roxi_username', 'RoxiSupport'));
    }

    public function test_regular_user_does_not_receive_the_shared_roxi_username(): void
    {
        config()->set('telegram.roxi.username', '@RoxiSupport');
        $user = User::factory()->create();

        $this->withoutVite()
            ->actingAs($user)
            ->get('/settings/telegram')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Telegram')
                ->where('roxi_username', null));
    }

    public function test_guest_cannot_start_roxi_login(): void
    {
        $this->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertUnauthorized();
    }

    public function test_regular_user_cannot_start_roxi_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertForbidden();
    }

    public function test_roxi_login_requires_recent_roxifile_password_confirmation(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertStatus(423);
    }

    public function test_roxi_login_confirmation_route_uses_the_existing_password_or_passkey_flow(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/settings/telegram/roxi-login/confirm')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_confirmed_roxi_login_opens_the_settings_flow_once(): void
    {
        $this->withoutVite();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/settings/telegram/roxi-login/confirm')
            ->assertRedirect(route('telegram.settings'));

        $this->get('/settings/telegram')
            ->assertInertia(fn (Assert $page) => $page
                ->where('roxi_login_open', true));

        $this->get('/settings/telegram')
            ->assertInertia(fn (Assert $page) => $page
                ->where('roxi_login_open', false));
    }

    public function test_roxi_login_cannot_use_the_session_while_the_worker_holds_its_lock(): void
    {
        config()->set('telegram.roxi.username', 'RoxiSupport');
        config()->set('telegram.app_id', '12345');
        config()->set('telegram.api_hash', 'test-api-hash');
        $admin = User::factory()->superAdmin()->create();
        $lock = Cache::lock(config('telegram.worker.lock'), 900);
        $this->assertTrue($lock->get());

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertConflict()
            ->assertJsonPath('message', 'The Roxi Telegram session is in use. Stop the worker and try again.');

        $lock->release();
    }

    public function test_roxi_login_rejects_a_phone_number_outside_e164_format(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson('/settings/telegram/roxi-login/phone', ['phone' => '5551234567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_super_admin_completes_phone_code_and_two_factor_steps_without_echoing_credentials(): void
    {
        config()->set('telegram.roxi.username', '@RoxiSupport');
        config()->set('telegram.app_id', '12345');
        config()->set('telegram.api_hash', 'test-api-hash');
        $client = \Mockery::mock('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient');
        $client->shouldReceive('isAuthorized')->once()->andReturn(false);
        $client->shouldReceive('requestCode')->once()->with('+15551234567');
        $client->shouldReceive('completeCode')->once()->with('0000')->andThrow(new RoxiLoginException('invalid_code', 'That Telegram login code is incorrect.', 422, true));
        $client->shouldReceive('completeCode')->once()->with('12345')->andReturn(true);
        $client->shouldReceive('completePassword')->once()->with('wrong')->andThrow(new RoxiLoginException('invalid_password', 'That Telegram two-step verification password is incorrect.', 422, true));
        $client->shouldReceive('completePassword')->once()->with('telegram-secret');
        $client->shouldReceive('username')->once()->andReturn('RoxiSupport');
        $client->shouldNotReceive('logout');
        $this->app->instance('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient', $client);

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertAccepted()
            ->assertExactJson(['step' => 'awaiting_code']);

        $this->postJson('/settings/telegram/roxi-login/code', ['code' => '0000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That Telegram login code is incorrect.');
        $this->assertTrue(Cache::has('telegram.roxi_login.in_progress'));

        $this->postJson('/settings/telegram/roxi-login/code', ['code' => '12345'])
            ->assertOk()
            ->assertExactJson(['step' => 'awaiting_password']);

        $this->postJson('/settings/telegram/roxi-login/password', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That Telegram two-step verification password is incorrect.');
        $this->assertTrue(Cache::has('telegram.roxi_login.in_progress'));

        $this->postJson('/settings/telegram/roxi-login/password', ['password' => 'telegram-secret'])
            ->assertOk()
            ->assertExactJson(['step' => 'connected'])
            ->assertDontSee('telegram-secret')
            ->assertSessionMissing('telegram.roxi_login.lock_owner');

        $this->assertFalse(Cache::has('telegram.roxi_login.in_progress'));
    }

    public function test_cancelling_roxi_login_clears_the_pending_session_and_releases_the_worker_lock(): void
    {
        config()->set('telegram.roxi.username', 'RoxiSupport');
        config()->set('telegram.app_id', '12345');
        config()->set('telegram.api_hash', 'test-api-hash');
        $client = \Mockery::mock('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient');
        $client->shouldReceive('isAuthorized')->once()->andReturn(false);
        $client->shouldReceive('requestCode')->once()->with('+15551234567');
        $client->shouldReceive('logout')->once();
        $this->app->instance('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient', $client);

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertAccepted();

        $this->postJson('/settings/telegram/roxi-login/cancel')
            ->assertOk()
            ->assertExactJson(['step' => 'awaiting_phone'])
            ->assertSessionMissing('telegram.roxi_login.lock_owner');

        $this->assertFalse(Cache::has('telegram.roxi_login.in_progress'));
        $this->assertTrue(Cache::lock(config('telegram.worker.lock'), 10)->get());
    }

    public function test_roxi_login_rejects_a_telegram_account_that_does_not_match_the_configured_username(): void
    {
        config()->set('telegram.roxi.username', 'RoxiSupport');
        config()->set('telegram.app_id', '12345');
        config()->set('telegram.api_hash', 'test-api-hash');
        $client = \Mockery::mock('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient');
        $client->shouldReceive('isAuthorized')->once()->andReturn(false);
        $client->shouldReceive('requestCode')->once()->with('+15551234567');
        $client->shouldReceive('completeCode')->once()->with('12345')->andReturn(false);
        $client->shouldReceive('username')->once()->andReturn('SomeoneElse');
        $client->shouldReceive('logout')->once();
        $this->app->instance('App\\Domains\\Telegram\\Contracts\\RoxiLoginSessionClient', $client);

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->postJson('/settings/telegram/roxi-login/phone', ['phone' => '+15551234567'])
            ->assertAccepted();

        $this->postJson('/settings/telegram/roxi-login/code', ['code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The signed-in Telegram account does not match @RoxiSupport.')
            ->assertSessionMissing('telegram.roxi_login.lock_owner');

        $this->assertFalse(Cache::has('telegram.roxi_login.in_progress'));
    }
}
