<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Files\Models\File;
use App\Domains\Telegram\Models\TelegramWorkerStatus;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Http\JsonResponse;

class TelegramWorkerStatusController
{
    public function __invoke(): JsonResponse
    {
        $status = TelegramWorkerStatus::query()->find(1);

        if ($status === null) {
            return response()->json(['running' => false, 'flagged_files' => 0]);
        }

        $running = $status->isRunning();

        // Admin-facing inconsistency count (spec §43): files flagged by the
        // reconciliation sweep that need triage. Never auto-deleted.
        $flaggedFiles = File::query()
            ->whereNotNull('metadata->reconciliation')
            ->count();

        return response()->json([
            'running' => $running,
            'roxi_connected' => $running && (bool) $status->roxi_connected,
            'session_loaded' => $running && (bool) $status->session_loaded,
            'pid' => $running ? $status->pid : null,
            'hostname' => $running ? $status->hostname : null,
            'started_at' => $status->started_at?->toIso8601String(),
            'last_heartbeat_at' => $status->last_heartbeat_at?->toIso8601String(),
            'last_activity_at' => $status->last_activity_at?->toIso8601String(),
            'last_successful_operation_at' => $status->last_successful_operation_at?->toIso8601String(),
            'last_error' => $running && $status->last_error !== null ? TelegramErrors::message($status->last_error) : null,
            'last_error_at' => $status->last_error_at?->toIso8601String(),
            'reconnect_count' => $status->reconnect_count,
            'flagged_files' => $flaggedFiles,
        ]);
    }
}
