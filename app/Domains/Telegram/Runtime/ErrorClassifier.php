<?php

namespace App\Domains\Telegram\Runtime;

use Amp\TimeoutException;
use App\Domains\Telegram\Data\RetryDecision;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SecurityException as MadelineSecurityException;

/**
 * Maps Telegram/AMPHP exceptions into application-level retry decisions.
 *
 * Retryable: transient network faults, rate limits (FloodWait), server-side
 * errors. Permanent: authorization, revoked sessions, permission errors,
 * unavailable chats/messages/files. Never blindly retry (spec §42).
 */
class ErrorClassifier
{
    /**
     * Classify a throwable from a Telegram operation.
     */
    public static function classify(\Throwable $e): RetryDecision
    {
        if ($e instanceof PersonalTelegramSessionUnavailable) {
            return new RetryDecision(retryable: false, delaySeconds: null, kind: 'personal_session_unavailable');
        }

        if ($e instanceof BotApiException) {
            return new RetryDecision($e->retryable, $e->retryAfter, $e->httpStatus === 429 ? 'rate_limit' : ($e->retryable ? 'network' : 'telegram_rejected'));
        }

        if ($e instanceof TimeoutException) {
            return new RetryDecision(retryable: true, delaySeconds: 5, kind: 'timeout');
        }

        if ($e instanceof RPCErrorException) {
            $message = strtolower($e->getMessage());

            if (str_starts_with($message, 'flood_wait_')) {
                $delay = (int) substr($message, strlen('flood_wait_'));

                return new RetryDecision(retryable: true, delaySeconds: max($delay, 1), kind: 'flood_wait');
            }

            if (in_array($message, ['flood_test_time_wait'], true) || str_contains($message, 'slowmode')) {
                return new RetryDecision(retryable: true, delaySeconds: 30, kind: 'flood_wait');
            }

            if (in_array($message, [
                'session_password_needed',
                'session_expired',
                'auth_key_unregistered',
                'auth_key_invalid',
                'user_deactivated',
                'user_deactivated_ban',
                'unauthorized',
                'api_id_invalid',
            ], true) || str_contains($message, 'unauthorized')) {
                return new RetryDecision(retryable: false, delaySeconds: null, kind: 'unauthorized');
            }

            if (in_array($message, [
                'chat_write_forbidden',
                'chat_send_media_forbidden',
                'chat_admin_required',
                'user_restricted',
                'user_not_mutual_contact',
                'peer_id_invalid',
                'channel_private',
                'channel_deleted',
                'chat_deleted',
                'chat_id_invalid',
            ], true)) {
                return new RetryDecision(retryable: false, delaySeconds: null, kind: 'forbidden_or_unavailable');
            }

            if (in_array($message, [
                'message_id_invalid',
                'message_empty',
                'file_reference_expired',
                'file_parts_invalid',
                'document_invalid',
                'photo_invalid',
                'media_empty',
            ], true)) {
                return new RetryDecision(retryable: false, delaySeconds: null, kind: 'message_or_file_unavailable');
            }

            if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
                return new RetryDecision(retryable: true, delaySeconds: 5, kind: 'timeout');
            }

            if (str_contains($message, 'too many requests') || str_contains($message, 'flood')) {
                return new RetryDecision(retryable: true, delaySeconds: 15, kind: 'flood_wait');
            }

            // Unknown RPC error: treat as permanent, it is deterministic per input.
            return new RetryDecision(retryable: false, delaySeconds: null, kind: 'telegram_rpc_error');
        }

        if ($e instanceof MadelineSecurityException) {
            return new RetryDecision(retryable: false, delaySeconds: null, kind: 'unauthorized');
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'could not connect to dc')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'broken pipe')
            || str_contains($message, 'curl error')
            || str_contains($message, 'network')
            || str_contains($message, 'dns')
            || str_contains($message, 'timed out')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'worker runtime not available')
        ) {
            return new RetryDecision(retryable: true, delaySeconds: 5, kind: 'network');
        }

        if (str_contains($message, 'revoked') || str_contains($message, 'unauthorized')) {
            return new RetryDecision(retryable: false, delaySeconds: null, kind: 'unauthorized');
        }

        return new RetryDecision(retryable: false, delaySeconds: null, kind: 'unknown');
    }
}
