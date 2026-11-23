<?php

namespace App\Domains\Telegram\Contracts;

use RuntimeException;

/**
 * The designated target account could not be resolved to a Telegram peer:
 * unknown @username, or a mobile number that is not discoverable.
 */
class PeerResolutionFailed extends RuntimeException {}
