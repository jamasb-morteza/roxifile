<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorizonConfigurationTest extends TestCase
{
    #[DataProvider('prefixes')]
    public function test_redis_prefix_respects_the_application_name_and_explicit_override(string $appName, ?string $prefix, string $expected): void
    {
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        $_ENV['APP_NAME'] = $_SERVER['APP_NAME'] = $appName;
        unset($_ENV['HORIZON_PREFIX'], $_SERVER['HORIZON_PREFIX']);

        if ($prefix !== null) {
            $_ENV['HORIZON_PREFIX'] = $_SERVER['HORIZON_PREFIX'] = $prefix;
        }

        try {
            $configuration = require config_path('horizon.php');

            $this->assertSame($expected, $configuration['prefix']);
        } finally {
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
        }
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function prefixes(): array
    {
        return [
            'application name' => ['Roxi File', null, 'roxi_file_horizon:'],
            'boolean true environment value' => ['true', null, '1_horizon:'],
            'boolean false environment value' => ['false', null, '_horizon:'],
            'explicit override' => ['Roxi File', 'custom:', 'custom:'],
        ];
    }
}
