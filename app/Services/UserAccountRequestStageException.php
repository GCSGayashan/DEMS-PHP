<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/** Non-sensitive transaction-stage context for an unexpected account-request failure. */
final class UserAccountRequestStageException extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        Throwable $previous
    ) {
        parent::__construct('Unexpected user account request failure.', 0, $previous);
    }
}
