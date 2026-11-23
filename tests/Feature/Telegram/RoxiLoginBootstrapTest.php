<?php

namespace Tests\Feature\Telegram;

use App\Domains\Telegram\Runtime\MadelineProtoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoxiLoginBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_prepares_a_private_session_parent_before_starting_the_adapter(): void
    {
        $temporaryStorage = sys_get_temp_dir().'/roxifile-login-'.Str::ulid();
        $this->app->useStoragePath($temporaryStorage);
        config(['telegram.app_id' => 1, 'telegram.api_hash' => str_repeat('a', 32), 'telegram.roxi.username' => 'bootstrap_test']);
        $observed = null;
        $factory = $this->mock(MadelineProtoFactory::class);
        $factory->shouldReceive('roxiSessionName')->once()->andReturn(storage_path('app/private/madeline/roxi'));
        $factory->shouldReceive('make')->once()->andReturnUsing(
            function (array $config, string $session) use (&$observed): never {
                $observed = dirname($session);
                throw new \RuntimeException('Test stopped before network or login.');
            },
        );
        try {
            $this->artisan('telegram:roxi:login')->assertFailed();
            $this->assertDirectoryExists($observed);
            $this->assertSame(0700, fileperms($observed) & 0777);
        } finally {
            File::deleteDirectory($temporaryStorage);
        }
    }
}
