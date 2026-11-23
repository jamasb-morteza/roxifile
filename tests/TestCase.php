<?php

namespace Tests;

use App\Domains\Telegram\Contracts\TelegramUploadStreamTransport;
use App\Domains\Telegram\Data\SendTelegramUploadStreamData;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\TelegramUploadStreamBridge;
use App\Domains\Telegram\Runtime\TelegramUploadStreamException;
use App\Domains\Telegram\Runtime\TelegramUploadStreamSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\Fortify\Features;
use Throwable;

use function Amp\async;

abstract class TestCase extends BaseTestCase
{
    protected TelegramUploadStreamTransport $telegramUploadStreamTransport;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')
            || $app['config']->get('database.connections.sqlite.url')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require an isolated SQLite in-memory database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('telegram-download');

        // Telegram jobs are routed to the redis connection via #[Connection]
        // attributes (production IPC path to telegram:worker). Under test,
        // feature assertions depend on inline execution (in-process fake
        // driver counters, operation completion), so the redis connection
        // resolves to the sync driver instead of the real redis server.
        config(['queue.connections.redis.driver' => 'sync']);

        $this->telegramUploadStreamTransport = new class(app(TelegramUploadStreamBridge::class)) implements TelegramUploadStreamTransport
        {
            public ?int $disconnectAfterBytes = null;

            public function __construct(private readonly TelegramUploadStreamBridge $bridge) {}

            public function send(SendTelegramUploadStreamData $data): void
            {
                if (Queue::getFacadeRoot() instanceof QueueFake) {
                    $size = $data->size;
                    if ($size <= TelegramUploadStreamSession::CHUNK_BYTES * 8) {
                        $session = $this->bridge->begin($data->operationId, $size);
                        $stream = fopen($data->sourcePath, 'rb');
                        if ($stream === false) {
                            $session->fail(new \RuntimeException('The upload source could not be opened.'));
                            throw new \RuntimeException('The upload source could not be opened.');
                        }

                        $actualSize = fstat($stream)['size'];
                        $emptyDeclaredFake = $actualSize === 0 && $size > 0;
                        $sentBytes = 0;
                        try {
                            while ($sentBytes < $size) {
                                $chunk = $emptyDeclaredFake
                                    ? str_repeat("\0", min(TelegramUploadStreamSession::CHUNK_BYTES, $size - $sentBytes))
                                    : fread($stream, TelegramUploadStreamSession::CHUNK_BYTES);
                                if (! is_string($chunk) || $chunk === '') {
                                    throw new \RuntimeException('The upload source could not be read.');
                                }

                                $session->write($chunk);
                                $sentBytes += strlen($chunk);
                            }
                            $session->finish();
                        } catch (Throwable $error) {
                            $session->fail($error);
                            throw $error;
                        } finally {
                            fclose($stream);
                        }
                    }

                    TelegramOperationJob::dispatch($data->operationId);

                    return;
                }

                $size = $data->size;
                $session = $this->bridge->begin($data->operationId, $size);
                $future = async(static function () use ($data): void {
                    (new TelegramOperationJob($data->operationId))->handle(app(TelegramOperationProcessor::class));
                });
                $stream = fopen($data->sourcePath, 'rb');

                if ($stream === false) {
                    $error = new \RuntimeException('The upload source could not be opened.');
                    $session->fail($error);
                    $future->await();

                    throw $error;
                }

                $sentBytes = 0;
                try {
                    $reportedSize = $size;
                    $actualSize = fstat($stream)['size'];
                    $emptyDeclaredFake = $actualSize === 0 && $reportedSize > 0;
                    while (! feof($stream)) {
                        $chunk = $emptyDeclaredFake
                            ? str_repeat("\0", min(TelegramUploadStreamSession::CHUNK_BYTES, $reportedSize - $sentBytes))
                            : fread($stream, TelegramUploadStreamSession::CHUNK_BYTES);
                        if ($chunk === false) {
                            throw new \RuntimeException('The upload source could not be read.');
                        }
                        if ($chunk === '') {
                            continue;
                        }

                        $session->write($chunk);
                        $sentBytes += strlen($chunk);
                        if ($this->disconnectAfterBytes !== null && $sentBytes >= $this->disconnectAfterBytes) {
                            throw new TelegramUploadStreamException('The upload client disconnected.');
                        }

                        if ($emptyDeclaredFake && $sentBytes >= $reportedSize) {
                            break;
                        }
                    }

                    $session->finish();
                } catch (Throwable $error) {
                    $session->fail($error);
                    $future->await();

                    throw $error;
                } finally {
                    fclose($stream);
                }

                $future->await();
            }
        };
        $this->app->instance(TelegramUploadStreamTransport::class, $this->telegramUploadStreamTransport);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
