<?php

namespace Tests\Feature\Auth;

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
    }

    public function test_google_redirect_is_available_when_credentials_are_configured(): void
    {
        Socialite::fake('google');

        $this->get(route('auth.google.redirect'))->assertRedirect();
    }

    public function test_google_redirect_returns_to_login_when_credentials_are_missing(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);

        $this->get(route('auth.google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Google sign-in is not configured.');
    }

    public function test_login_and_registration_pages_offer_google_authentication_when_configured(): void
    {
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Login')
            ->where('canGoogleAuthenticate', true));

        $this->get(route('register'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Register')
            ->where('canGoogleAuthenticate', true));
    }

    public function test_google_callback_registers_and_authenticates_a_verified_account(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'name' => 'Google User',
            'email' => 'google@example.com',
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Google User',
            'email' => 'google@example.com',
            'google_id' => 'google-user-123',
        ]);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_google_callback_authenticates_an_existing_linked_account(): void
    {
        $user = User::factory()->create(['google_id' => 'google-user-123']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'name' => 'Updated Google Name',
            'email' => 'google@example.com',
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_google_callback_rejects_unverified_email(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'email' => 'google@example.com',
            'email_verified' => false,
        ]));

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_callback_links_an_existing_account_with_the_same_verified_email(): void
    {
        $user = User::factory()->create(['email' => 'google@example.com']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'different-google-user',
            'email' => 'google@example.com',
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'different-google-user',
        ]);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_google_callback_does_not_replace_a_different_linked_google_account(): void
    {
        $user = User::factory()->create([
            'email' => 'google@example.com',
            'google_id' => 'original-google-user',
        ]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'different-google-user',
            'email' => 'google@example.com',
            'email_verified' => true,
        ]));

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'original-google-user',
        ]);
    }

    public function test_google_callback_preserves_the_two_factor_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create(['google_id' => 'google-user-123']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'email' => $user->email,
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth.google.callback'));

        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
    }
}
