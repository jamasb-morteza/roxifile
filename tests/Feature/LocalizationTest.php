<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_locale_cookie_sets_persian_inertia_and_document_direction(): void
    {
        $this->withoutVite()
            ->withUnencryptedCookie('locale', 'fa')
            ->get(route('home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('locale', 'fa')
                ->where('direction', 'rtl'))
            ->assertSee('lang="fa" dir="rtl"', false)
            ->assertSee('<body class="font-sans antialiased" dir="rtl">', false);
    }

    public function test_guest_can_choose_persian_and_keep_it_in_a_cookie(): void
    {
        $this->from(route('home'))
            ->post('/locale', ['locale' => 'fa'])
            ->assertRedirect(route('home'))
            ->assertCookie('locale', 'fa', encrypted: false);
    }

    public function test_authenticated_locale_choice_survives_logout_and_overrides_guest_cookie(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('home'))
            ->post('/locale', ['locale' => 'fa'])
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'fa',
        ]);

        $this->post(route('logout'));
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->withoutVite()
            ->withUnencryptedCookie('locale', 'en')
            ->get(route('home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('locale', 'fa')
                ->where('direction', 'rtl'));
    }

    public function test_invalid_locale_returns_a_persian_validation_error(): void
    {
        $this->withUnencryptedCookie('locale', 'fa')
            ->from(route('home'))
            ->post('/locale', ['locale' => 'de'])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors(['locale' => 'زبان انتخاب‌شده معتبر نیست.']);
    }

    public function test_unsupported_guest_cookie_falls_back_to_english(): void
    {
        $this->withoutVite()
            ->withUnencryptedCookie('locale', 'de')
            ->get(route('home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('locale', 'en')
                ->where('direction', 'ltr'))
            ->assertSee('<body class="font-sans antialiased" dir="ltr">', false);
    }
}
