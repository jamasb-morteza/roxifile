<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    public function test_dashboard_access_is_restricted_to_super_admins(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(new User(['is_super_admin' => false]))->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser(new User(['is_super_admin' => true]))->allows('viewHorizon'));
    }

    public function test_horizon_consumes_only_the_default_redis_queue(): void
    {
        foreach (config('horizon.environments') as $supervisors) {
            foreach ($supervisors as $name => $overrides) {
                $options = array_replace(config("horizon.defaults.{$name}"), $overrides);
                $this->assertSame('redis', $options['connection']);
                $this->assertSame(['default'], $options['queue']);
                $this->assertLessThan(config('queue.connections.redis.retry_after'), $options['timeout']);
            }
        }
    }
}
