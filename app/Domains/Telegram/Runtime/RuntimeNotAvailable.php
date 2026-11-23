<?php

namespace App\Domains\Telegram\Runtime;

/**
 * Thrown when Telegram infrastructure is requested from a process that does
 * not host the long-running runtime (e.g. HTTP requests). Classified as
 * retryable: the worker will pick the operation up.
 */
class RuntimeNotAvailable extends \RuntimeException {}
