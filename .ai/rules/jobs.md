---
paths:
    - 'app/Domains/Telegram/Jobs/**'
---

# Jobs

## Route Telegram jobs with #[Connection]/#[Queue] attributes, never viaQueue()/viaConnection()

Laravel 13 never calls job methods viaQueue()/viaConnection() - they are dead code; the bus silently falls back to the default connection/queue (database/default). Telegram jobs MUST carry class attributes #[Connection('redis')] and #[Queue('telegram')] (Illuminate\Queue\Attributes). Only telegram:worker consumes that queue and only it holds the MadelineProto runtime (RoxiRuntime); a generic `php artisan queue:work` dead-letters these jobs via RuntimeNotAvailable retries. When inspecting queues with redis-cli, keys carry the prefix roxifile-database- (e.g. roxifile-database-queues:telegram). In tests, tests/TestCase.php forces `queue.connections.redis.driver = sync` so attributed jobs still run inline (feature tests assert in-process fake-driver counters); remove that only if every telegram-dispatching test fakes the queue.
