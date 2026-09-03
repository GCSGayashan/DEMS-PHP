<?php
declare(strict_types=1);

namespace App\Core;

use DomainException;

/** A controlled HTTP authorization failure that must not expose a stack trace. */
final class AuthorizationException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $requiredAuthority
    ) {
        parent::__construct($message);
    }
}
