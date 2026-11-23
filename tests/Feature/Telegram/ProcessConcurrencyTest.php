<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramWorkerStatus;
use App\Domains\Telegram\Runtime\TelegramQueueConsumer;
use App\Domains\Telegram\Runtime\WorkerLock;
use App\Domains\Telegram\Services\AmpTelegramHttpHandler;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use App\Domains\Telegram\StorageManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class ProcessConcurrencyTest extends TestCase
{
    private ?string $schema = null;

    private ?string $barriers = null;

    /** @var list<int> */
    private array $children = [];

    private ?string $queueName = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ROXIFILE_PROCESS_TESTS') !== '1') {
            $this->markTestSkipped('Opt-in: dedicated PostgreSQL test database and Redis are required.');
        }
        $database = getenv('ROXIFILE_TEST_PG_DATABASE') ?: 'roxifile_concurrency_test';
        if (! str_ends_with($database, '_test')) {
            $this->fail('Process tests require a dedicated database ending in _test.');
        }
        $this->schema = 'concurrency_'.strtolower(Str::random(12));
        $this->barriers = sys_get_temp_dir().'/roxifile-'.$this->schema;
        mkdir($this->barriers, 0700);
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => getenv('ROXIFILE_TEST_PG_HOST') ?: '127.0.0.1',
            'database.connections.pgsql.port' => getenv('ROXIFILE_TEST_PG_PORT') ?: '55432',
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.username' => getenv('ROXIFILE_TEST_PG_USERNAME') ?: 'postgres',
            'database.connections.pgsql.password' => getenv('ROXIFILE_TEST_PG_PASSWORD') ?: '',
            'database.connections.pgsql.search_path' => $this->schema,
            'database.redis.default.url' => null,
            'database.redis.default.host' => getenv('ROXIFILE_TEST_REDIS_HOST') ?: '127.0.0.1',
            'database.redis.default.port' => getenv('ROXIFILE_TEST_REDIS_PORT') ?: '56379',
            'database.redis.default.password' => getenv('ROXIFILE_TEST_REDIS_PASSWORD') ?: null,
            'database.redis.default.database' => 0,
            'database.redis.options.prefix' => $this->schema.':',
            'cache.default' => 'redis', 'cache.stores.redis.connection' => 'default',
            'cache.stores.redis.lock_connection' => 'default',
            'cache.prefix' => $this->schema,
            'queue.connections.redis.driver' => 'redis', 'queue.connections.redis.retry_after' => 1,
        ]);
        DB::purge('pgsql');
        Redis::purge('default');
        DB::statement('CREATE SCHEMA '.$this->schema);
        $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        if ($this->schema !== null) {
            if ($this->queueName !== null) {
                Queue::connection('redis')->clear($this->queueName);
            }
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
            if ($this->barriers !== null) {
                foreach (glob($this->barriers.'/*') ?: [] as $path) {
                    unlink($path);
                }
                rmdir($this->barriers);
            }
        }
        parent::tearDown();
    }

    public function test_reserved_job_redelivery_in_a_second_process_cannot_start_a_second_transfer(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id]);
        $path = 'telegram-upload/'.$this->schema;
        Storage::disk('local')->put($path, 'bytes');
        $operation = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id,
            'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id,
            'operation' => 'upload', 'status' => 'pending', 'metadata' => ['temp_path' => $path],
        ]);
        $driver = new FakeTelegramStorageDriver;
        $barriers = $this->barriers;
        $driver->duringUpload = function () use ($barriers): void {
            file_put_contents($barriers.'/transfers', "transfer\n", FILE_APPEND | LOCK_EX);
            file_put_contents($barriers.'/started', 'yes');
            $this->awaitFile($barriers.'/release');
        };
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $this->queueName = 'isolated-'.$this->schema;
        Queue::connection('redis')->push(new TelegramOperationJob($operation->id), '', $this->queueName);
        $first = $this->forkConsumer();
        $this->awaitFile($barriers.'/started');
        // Advance RedisQueue's reservation clock without waiting 90 real seconds.
        $this->travel(3)->seconds();
        $second = $this->forkConsumer();
        $this->assertChildSucceeded($second);
        $this->assertSame("transfer\n", file_get_contents($barriers.'/transfers'));
        file_put_contents($barriers.'/release', 'yes');
        $this->assertChildSucceeded($first);
        DB::purge('pgsql');
        $this->assertSame('completed', $operation->fresh()->status->value);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_expired_runtime_owner_cannot_refresh_or_release_the_new_owners_redis_lock(): void
    {
        $name = 'runtime-'.$this->schema;
        $first = new WorkerLock($name, 1);
        $this->assertTrue($first->acquire());
        // Redis expiration uses server time, independently of Carbon.
        usleep(1_100_000);
        $second = new WorkerLock($name, 10);
        $this->assertTrue($second->acquire());
        $this->assertFalse($first->refresh());
        $first->release();
        $this->assertFalse((new WorkerLock($name, 10))->acquire());
        $this->assertTrue($second->refresh());
        $second->release();
    }

    public function test_queue_consumer_enforces_the_limit_and_drains_before_stopping(): void
    {
        $this->queueName = 'isolated-'.$this->schema;
        config(['telegram.worker.queue' => $this->queueName, 'telegram.worker.max_concurrent_operations' => 2]);
        for ($number = 0; $number < 3; $number++) {
            Queue::connection('redis')->push(new ConcurrentQueueProbe, '', $this->queueName);
        }
        ConcurrentQueueProbe::$peak = 0;
        ConcurrentQueueProbe::$active = 0;
        $consumer = app(TelegramQueueConsumer::class);

        $consumer->tick();
        $consumer->tick();
        $this->assertFalse($consumer->isDrained());
        $consumer->stop();
        \Amp\delay(0.1);
        $consumer->tick();

        $this->assertTrue($consumer->isDrained());
        $this->assertSame(2, ConcurrentQueueProbe::$peak);
        $this->assertSame(1, Queue::connection('redis')->size($this->queueName));
    }

    public function test_two_invite_codes_for_the_same_target_cannot_both_connect(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'roxi', 'is_system' => true]);
        $service = app(RoxiProvisioningService::class);
        $codes = [];
        for ($number = 0; $number < 2; $number++) {
            $connection = TelegramConnection::factory()->create([
                'telegram_account_id' => $account->id, 'method' => 'invite', 'status' => 'pending', 'target_user_id' => 555,
            ]);
            $codes[] = $service->issueCode($connection);
        }
        $pids = [];
        foreach ($codes as $code) {
            DB::purge('pgsql');
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $this->awaitFile($this->barriers.'/invite-go');
                    $service->handleIncomingCode($code, 555, ['chat_id' => 555, 'chat_type' => 'private', 'chat_title' => 'Target', 'chat_username' => null]);
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($this->barriers.'/error-'.getmypid(), $error->getMessage());
                    exit(1);
                }
            }
            if ($pid < 0) {
                $this->fail('Could not fork invite confirmation.');
            }
            $this->children[] = $pid;
            $pids[] = $pid;
        }
        file_put_contents($this->barriers.'/invite-go', 'yes');
        foreach ($pids as $pid) {
            $this->assertChildSucceeded($pid);
        }
        DB::purge('pgsql');
        $this->assertSame(1, TelegramConnection::query()->where('telegram_account_id', $account->id)->where('status', 'connected')->count());
        $this->assertSame(1, TelegramConnection::query()->where('telegram_account_id', $account->id)->where('status', 'error')->count());
    }

    public function test_bot_http_transport_yields_so_heartbeats_can_run_during_network_waits(): void
    {
        $server = \Amp\Socket\listen('127.0.0.1:0');
        $received = '';
        $serving = \Amp\async(function () use ($server, &$received): void {
            $socket = $server->accept();
            $headers = '';
            while (! str_contains($headers, "\r\n\r\n")) {
                $headers .= $socket->read();
            }
            [$head, $received] = explode("\r\n\r\n", $headers, 2);
            preg_match('/Content-Length: (\d+)/i', $head, $match);
            while (strlen($received) < (int) ($match[1] ?? 0)) {
                $received .= $socket->read();
            }
            \Amp\delay(0.05);
            $socket->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
            $socket->close();
        });
        $heartbeatRan = false;
        $heartbeat = \Amp\async(function () use (&$heartbeatRan): void {
            \Amp\delay(0.01);
            $heartbeatRan = true;
        });

        $handler = app(AmpTelegramHttpHandler::class);
        $url = 'http://'.$server->getAddress();
        Http::allowStrayRequests([$url]);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'streamed multipart bytes');
        rewind($stream);
        $response = Http::timeout(1)->setHandler($handler)
            ->attach('document', $stream, 'probe.txt')->post($url, ['chat_id' => 555]);

        $this->assertTrue($heartbeatRan, 'The network request must yield to the worker event loop.');
        $this->assertSame(200, $response->status());
        $this->assertSame('{}', $response->body());
        $this->assertStringContainsString('streamed multipart bytes', $received);
        $this->assertStringContainsString('name="chat_id"', $received);
        $serving->await();
        $heartbeat->await();
        $server->close();
    }

    public function test_http_upload_crosses_the_real_redis_queue_and_returns_the_same_bytes_after_background_download(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $driver = new class($this->barriers) extends FakeTelegramStorageDriver
        {
            public function __construct(private readonly string $directory) {}

            public function upload(FileOperation $operation, File $file, string $tempPath, callable $onProgress): TelegramUploadResult
            {
                $result = parent::upload($operation, $file, $tempPath, $onProgress);
                file_put_contents($this->directory.'/remote-'.$result->telegramMessageId, file_get_contents($tempPath));

                return $result;
            }

            public function download(File $file, string $destinationPath): void
            {
                file_put_contents($destinationPath, file_get_contents($this->directory.'/remote-'.$file->telegram_message_id));
            }
        };
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $this->queueName = 'telegram';
        $bytes = 'HTTP through real Redis bytes';
        $upload = $this->actingAs($connection->user)->postJson('/files/upload', [
            'connection_id' => $connection->id,
            'file' => UploadedFile::fake()->createWithContent('queued.txt', $bytes),
        ])->assertAccepted()->assertJsonPath('status', 'pending');
        $this->assertChildSucceeded($this->forkConsumer($upload->json('operation_id')));
        DB::purge('pgsql');
        $fileId = $upload->json('file_id');
        $prepared = $this->postJson('/files/'.$fileId.'/prepare-download')->assertAccepted()->assertJsonPath('status', 'pending');
        $url = '/files/'.$fileId.'/download?operation_id='.$prepared->json('operation_id');
        $this->getJson($url)->assertConflict();
        $this->assertChildSucceeded($this->forkConsumer($prepared->json('operation_id')));
        DB::purge('pgsql');
        $response = $this->get($url)->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame(hash('sha256', $bytes), File::query()->findOrFail($fileId)->checksum);
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $download = FileOperation::query()->findOrFail($prepared->json('operation_id'));
        Storage::disk('local')->delete($download->metadata['downloaded_path']);
    }

    public function test_parallel_http_uploads_cannot_oversubscribe_the_same_users_quota(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'size' => 90]);
        config(['telegram.storage.max_user_bytes' => 100]);
        $this->actingAs($connection->user);
        $this->queueName = 'telegram';
        $pids = [];
        for ($number = 0; $number < 2; $number++) {
            DB::purge('pgsql');
            Redis::purge('default');
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $this->awaitFile($this->barriers.'/quota-go');
                    $response = $this->postJson('/files/upload', [
                        'connection_id' => $connection->id,
                        'file' => UploadedFile::fake()->createWithContent('quota.txt', '123456'),
                    ]);
                    file_put_contents($this->barriers.'/quota-'.$number, (string) $response->status());
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($this->barriers.'/error-'.getmypid(), $error->getMessage());
                    exit(1);
                }
            }
            if ($pid < 0) {
                $this->fail('Could not fork the quota request.');
            }
            $this->children[] = $pid;
            $pids[] = $pid;
        }
        file_put_contents($this->barriers.'/quota-go', 'yes');
        foreach ($pids as $pid) {
            $this->assertChildSucceeded($pid);
        }
        $statuses = [file_get_contents($this->barriers.'/quota-0'), file_get_contents($this->barriers.'/quota-1')];
        sort($statuses);
        $this->assertSame(['202', '422'], $statuses);
        DB::purge('pgsql');
        $this->assertSame(2, File::query()->where('user_id', $connection->user_id)->count());
        foreach (FileOperation::query()->cursor() as $operation) {
            Storage::disk('local')->delete($operation->metadata['temp_path']);
        }
    }

    public function test_bot_only_worker_renews_ownership_during_a_job_and_drains_after_sigterm(): void
    {
        $this->queueName = 'worker-'.$this->schema;
        $lockName = 'lock-'.$this->schema;
        config(['telegram.app_id' => null, 'telegram.api_hash' => null,
            'telegram.worker.queue' => $this->queueName, 'telegram.worker.lock' => $lockName,
            'telegram.worker.lock_ttl_seconds' => 2, 'telegram.worker.heartbeat_interval_seconds' => 1]);
        Queue::connection('redis')->push(new LongWorkerProbe($this->barriers), '', $this->queueName);
        DB::purge('pgsql');
        Redis::purge('default');
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $this->artisan('telegram:worker')->assertSuccessful();
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($this->barriers.'/error-'.getmypid(), $error->getMessage());
                exit(1);
            }
        }
        if ($pid < 0) {
            $this->fail('Could not fork the worker lifecycle probe.');
        }
        $this->children[] = $pid;
        $this->awaitFile($this->barriers.'/worker-started');
        usleep(2_100_000);
        $intruder = new WorkerLock($lockName, 2);
        $acquired = $intruder->acquire();
        posix_kill($pid, SIGTERM);
        $this->assertChildSucceeded($pid);
        $intruder->release();

        $this->assertFalse($acquired, 'A yielding in-flight job must not let the runtime lock expire.');
        $this->assertFileExists($this->barriers.'/worker-finished');
        DB::purge('pgsql');
        $this->assertFalse(TelegramWorkerStatus::query()->findOrFail(1)->isRunning());
    }

    private function forkConsumer(?int $untilOperationId = null): int
    {
        DB::purge('pgsql');
        Redis::purge('default');
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Could not fork the isolated queue consumer.');
        }
        if ($pid === 0) {
            try {
                for ($delivery = 0; $delivery < 50; $delivery++) {
                    $job = Queue::connection('redis')->pop($this->queueName);
                    if ($job === null) {
                        throw new \RuntimeException('The isolated queue had no delivery.');
                    }
                    $job->fire();
                    if ($untilOperationId === null || FileOperation::query()->findOrFail($untilOperationId)->status->value === 'completed') {
                        exit(0);
                    }
                }
                throw new \RuntimeException('The isolated operation did not complete within the delivery limit.');
            } catch (Throwable $error) {
                file_put_contents($this->barriers.'/error-'.getmypid(), $error->getMessage());
                exit(1);
            }
        }
        $this->children[] = $pid;

        return $pid;
    }

    private function assertChildSucceeded(int $pid): void
    {
        $deadline = microtime(true) + 10;
        do {
            $finished = pcntl_waitpid($pid, $status, WNOHANG);
            if ($finished === $pid) {
                $this->children = array_values(array_diff($this->children, [$pid]));
                $errorFile = $this->barriers.'/error-'.$pid;
                $this->assertSame(0, pcntl_wexitstatus($status), is_file($errorFile) ? file_get_contents($errorFile) : 'Queue consumer failed.');

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->fail('Isolated queue consumer timed out.');
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for the isolated transfer barrier.');
            }
            usleep(10_000);
        }
    }
}

class ConcurrentQueueProbe implements ShouldQueue
{
    public static int $active = 0;

    public static int $peak = 0;

    public function handle(): void
    {
        self::$active++;
        self::$peak = max(self::$peak, self::$active);
        \Amp\delay(0.02);
        self::$active--;
    }
}

class LongWorkerProbe implements ShouldQueue
{
    public function __construct(public readonly string $directory) {}

    public function handle(): void
    {
        file_put_contents($this->directory.'/worker-started', 'yes');
        \Amp\delay(2.3);
        file_put_contents($this->directory.'/worker-finished', 'yes');
    }
}
