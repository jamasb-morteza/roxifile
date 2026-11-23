<?php

namespace App\Domains\Telegram\Runtime;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Database\Postgres;
use LogicException;

/**
 * Builds MadelineProto settings from config/telegram.php.
 *
 * Session state lives in a dedicated PostgreSQL database (roxifile_madeline);
 * the application never queries its tables (design doc §6.9).
 */
class MadelineProtoFactory
{
    public function roxiSessionName(): string
    {
        return storage_path('app/private/madeline/roxi');
    }

    /**
     * @param  array<string, mixed>  $config  config('telegram.*') root array
     */
    public function build(array $config, string $session): Settings
    {
        $settings = new Settings;

        $settings->setAppInfo(
            (new AppInfo)
                ->setApiId((int) $config['app_id'])
                ->setApiHash((string) $config['api_hash'])
                ->setDeviceModel((string) ($config['roxi']['device_model'] ?? 'Roxivia Web')),
        );

        $db = $config['database'] ?? [];

        $settings->setDb(
            (new Postgres)
                ->setUri(sprintf('tcp://%s:%s', $db['host'] ?? '127.0.0.1', $db['port'] ?? '5432'))
                ->setDatabase((string) ($db['database'] ?? 'roxifile_madeline'))
                ->setUsername((string) ($db['username'] ?? ''))
                ->setPassword((string) ($db['password'] ?? '')),
        );

        return $settings;
    }

    /**
     * Instantiate the MadelineProto API for the given session (async boot).
     *
     * @param  array<string, mixed>  $config
     */
    public function make(array $config, string $session): API
    {
        return new API($session, $this->build($config, $session));
    }

    /**
     * Build settings for an owner-scoped personal session.
     *
     * MadelineProto's database serializer is not encrypted. Production
     * deployments must confirm encryption for the database storage and its
     * backups before personal authorization state can be persisted.
     *
     * @param  array<string, mixed>  $config
     */
    public function buildPersonal(array $config, string $session): Settings
    {
        if (($config['database']['encryption_at_rest_confirmed'] ?? false) !== true) {
            throw new LogicException('Personal Telegram sessions require encrypted PostgreSQL storage and backups.');
        }

        $settings = $this->build($config, $session);
        $database = $settings->getDb();

        if (! $database instanceof Postgres) {
            throw new LogicException('Personal Telegram sessions require the dedicated PostgreSQL database.');
        }

        // Pin the ORM tables to this RoxiFile owner/account rather than
        // MadelineProto's fallback Telegram-user ID, which can be shared.
        $database->setEphemeralFilesystemPrefix('p'.substr(hash('sha256', basename($session)), 0, 32));

        return $settings;
    }

    /**
     * Instantiate a personal MadelineProto API with owner-scoped persistence.
     *
     * @param  array<string, mixed>  $config
     */
    public function makePersonal(array $config, string $session): API
    {
        return new API($session, $this->buildPersonal($config, $session));
    }
}
