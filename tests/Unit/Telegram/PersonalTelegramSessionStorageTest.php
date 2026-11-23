<?php

namespace Tests\Unit\Telegram;

use App\Domains\Telegram\Runtime\MadelineProtoFactory;
use danog\MadelineProto\Settings\Database\Postgres;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class PersonalTelegramSessionStorageTest extends TestCase
{
    public function test_personal_sessions_fail_closed_without_database_encryption_confirmation(): void
    {
        try {
            (new MadelineProtoFactory)->buildPersonal($this->config(false), '/private/personal/session-one');
            $this->fail('Personal session settings should require confirmed database encryption.');
        } catch (LogicException $exception) {
            $this->assertSame('Personal Telegram sessions require encrypted PostgreSQL storage and backups.', $exception->getMessage());
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_personal_sessions_use_distinct_database_prefixes_when_storage_is_confirmed_encrypted(): void
    {
        $factory = new MadelineProtoFactory;
        $firstSettings = $factory->buildPersonal($this->config(true), '/private/personal/session-one');
        $secondSettings = $factory->buildPersonal($this->config(true), '/private/personal/session-two');
        $firstDatabase = $firstSettings->getDb();
        $secondDatabase = $secondSettings->getDb();

        $this->assertInstanceOf(Postgres::class, $firstDatabase);
        $this->assertInstanceOf(Postgres::class, $secondDatabase);
        $this->assertNotNull($firstDatabase->getEphemeralFilesystemPrefix());
        $this->assertNotSame($firstDatabase->getEphemeralFilesystemPrefix(), $secondDatabase->getEphemeralFilesystemPrefix());
    }

    /** @return array<string, mixed> */
    private function config(bool $encryptionAtRestConfirmed): array
    {
        return [
            'app_id' => 12345,
            'api_hash' => str_repeat('a', 32),
            'database' => [
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'roxifile_madeline',
                'username' => 'telegram',
                'password' => 'secret',
                'encryption_at_rest_confirmed' => $encryptionAtRestConfirmed,
            ],
        ];
    }
}
