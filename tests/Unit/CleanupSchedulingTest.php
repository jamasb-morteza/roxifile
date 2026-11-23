<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class CleanupSchedulingTest extends TestCase
{
    public function test_temp_file_cleanup_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $event = $this->scheduledEvent('telegram:cleanup-uploads');

        $this->assertNotNull($event, 'telegram:cleanup-uploads must be registered in the schedule.');
        $this->assertSame('*/5 * * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_reconciliation_sweep_is_scheduled_daily(): void
    {
        $event = $this->scheduledEvent('telegram:reconcile');

        $this->assertNotNull($event, 'telegram:reconcile must be registered in the schedule.');
        $this->assertSame('30 3 * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_stale_connection_verification_is_scheduled(): void
    {
        $event = $this->scheduledEvent('telegram:verify-stale');

        $this->assertNotNull($event, 'telegram:verify-stale must be registered in the schedule.');
        $this->assertSame('0 */6 * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function scheduledEvent(string $command): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command, $command)) {
                return $event;
            }
        }

        return null;
    }
}
