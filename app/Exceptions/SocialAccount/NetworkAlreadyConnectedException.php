<?php

declare(strict_types=1);

namespace App\Exceptions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use RuntimeException;

class NetworkAlreadyConnectedException extends RuntimeException
{
    public function __construct(
        public readonly Platform $platform,
        public readonly string $messageKey = 'network_taken',
    ) {
        parent::__construct("This workspace already has a {$platform->network()} account connected.");
    }

    /**
     * The provider handed back an account other than the one being reconnected,
     * which is a different problem from the network slot being taken.
     */
    public static function identityMismatch(Platform $platform): self
    {
        return new self($platform, 'wrong_account');
    }
}
