<?php

use Illuminate\Support\Facades\Schedule;

// Phase 14: temp-file hygiene + reconciliation sweeps (spec §43, §47).
Schedule::command('telegram:cleanup-uploads')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Remove abandoned Telegram temp files');

Schedule::command('telegram:reconcile')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Sample stored files and verify they still exist on Telegram');

Schedule::command('telegram:verify-stale')
    ->everySixHours()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Queue re-verification for connections with stale last_verified_at');

// Invite TTL sweep (ADR-0001): pending invite connections expire after
// config('telegram.storage.invite_code_ttl_minutes') — default 5.
Schedule::command('telegram:prune-pending-invites')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Delete expired pending invite connections and retract their code messages');

Schedule::command('telegram:recover-operations')->everyMinute()->onOneServer()->withoutOverlapping();

Schedule::command('telegram:cleanup-accounts')->everyMinute()->onOneServer()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
