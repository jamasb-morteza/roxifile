<?php

namespace Tests\Feature\Auth;

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin_user_when_email_is_unknown(): void
    {
        $this->artisan('user:make-super-admin', [
            'email' => 'admin@example.com',
            '--name' => 'Ada Admin',
            '--password' => 'secret-123',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($user->is_super_admin);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame('Ada Admin', $user->name);
        $this->assertTrue(Hash::check('secret-123', $user->password));
    }

    public function test_promotes_existing_user_without_touching_credentials(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;

        $this->artisan('user:make-super-admin', ['email' => $user->email])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_super_admin);
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame(1, User::query()->where('email', $user->email)->count());
    }

    public function test_generates_and_prints_password_for_new_users(): void
    {
        Artisan::call('user:make-super-admin', ['email' => 'fresh@example.com']);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Generated password \(shown once\): (\S+)/', $output, $output);

        preg_match('/Generated password \(shown once\): (\S+)/', $output, $matches);

        $user = User::query()->where('email', 'fresh@example.com')->firstOrFail();

        $this->assertTrue($user->is_super_admin);
        $this->assertTrue(Hash::check($matches[1], $user->password));
    }

    public function test_rejects_invalid_email_address(): void
    {
        $this->artisan('user:make-super-admin', ['email' => 'not-an-email'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
