<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

final class LocationDirectEditPolicy
{
    public static function allowed(): bool
    {
        $user = Auth::user();
        $context = Auth::activeContext();

        return $user !== null
            && $context !== null
            && (string)($context['role_code'] ?? '') === 'SYSTEM_ADMIN'
            && Auth::can('location.edit');
    }
}
