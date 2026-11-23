<?php

namespace Tests\Unit;

use App\Domains\Telegram\Runtime\ErrorClassifier;
use danog\MadelineProto\RPCErrorException;
use PHPUnit\Framework\TestCase;

class ErrorClassifierTest extends TestCase
{
    public function test_timeouts_are_retryable(): void
    {
        $decision = ErrorClassifier::classify(new \RuntimeException('Connection timed out after 30s'));

        $this->assertTrue($decision->retryable);
        $this->assertSame('network', $decision->kind);
        $this->assertNotNull($decision->delaySeconds);
    }

    public function test_network_faults_are_retryable(): void
    {
        foreach (['connection reset by peer', 'Could not connect to dc 2', 'curl error 7'] as $message) {
            $decision = ErrorClassifier::classify(new \RuntimeException($message));

            $this->assertTrue($decision->retryable, $message.' must be retryable');
            $this->assertSame('network', $decision->kind);
        }
    }

    public function test_flood_wait_is_retryable_with_the_requested_delay(): void
    {
        $decision = ErrorClassifier::classify(self::rpc('FLOOD_WAIT_42'));

        $this->assertTrue($decision->retryable);
        $this->assertSame('flood_wait', $decision->kind);
        $this->assertSame(42, $decision->delaySeconds);
    }

    public function test_authorization_failures_are_permanent(): void
    {
        foreach (['auth_key_unregistered', 'session_expired', 'user_deactivated'] as $message) {
            $decision = ErrorClassifier::classify(self::rpc($message));

            $this->assertFalse($decision->retryable, $message.' must be permanent');
            $this->assertSame('unauthorized', $decision->kind);
        }
    }

    public function test_forbidden_chats_are_permanent(): void
    {
        foreach (['peer_id_invalid', 'channel_private', 'chat_write_forbidden'] as $message) {
            $decision = ErrorClassifier::classify(self::rpc($message));

            $this->assertFalse($decision->retryable, $message.' must be permanent');
        }
    }

    public function test_missing_messages_are_permanent(): void
    {
        $decision = ErrorClassifier::classify(self::rpc('MESSAGE_ID_INVALID'));

        $this->assertFalse($decision->retryable);
        $this->assertSame('message_or_file_unavailable', $decision->kind);
    }

    public function test_unknown_rpc_errors_are_permanent(): void
    {
        // Deterministic per input: blindly retrying would never help (spec §42).
        $decision = ErrorClassifier::classify(self::rpc('SOME_NEW_RPC_ERROR'));

        $this->assertFalse($decision->retryable);
    }

    public function test_generic_unknown_errors_are_permanent(): void
    {
        $decision = ErrorClassifier::classify(new \LogicException('something odd happened'));

        $this->assertFalse($decision->retryable);
        $this->assertSame('unknown', $decision->kind);
    }

    public function test_error_label_truncates_long_messages(): void
    {
        $decision = ErrorClassifier::classify(new \LogicException(str_repeat('x', 300)));

        $this->assertLessThanOrEqual(500, mb_strlen($decision->errorLabel('original message')));
    }

    /**
     * The library's RPCErrorException constructor is protected; tests build
     * one through an exposing subclass.
     */
    private static function rpc(string $message): RPCErrorException
    {
        return new class($message) extends RPCErrorException
        {
            public function __construct(string $rpc)
            {
                parent::__construct($rpc, $rpc, 400, 'tests', null);
            }
        };
    }
}
