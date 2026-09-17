<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;

/**
 * Authorizes the exceptional, immediate edit of an existing assignment.
 *
 * Permission still controls the assignment family. The selected Active
 * Working Context controls whether the current request is operating at Head
 * Office; another role owned by the same user is deliberately not borrowed.
 */
final class AssignmentDirectEditPolicy
{
    private const ROLE_CODES = [
        'NATIONAL_SUBJECT_OFFICER',
        'NATIONAL_ADMIN',
        'SYSTEM_ADMIN',
    ];

    public static function allowed(string $permission): bool
    {
        $context = Auth::activeContext(false);
        if ($context === null
            || !Auth::can($permission)
            || !in_array((string)($context['role_code'] ?? ''), self::ROLE_CODES, true)) {
            return false;
        }

        if ((string)$context['role_code'] === 'SYSTEM_ADMIN') {
            return (string)($context['role_level'] ?? '') === 'SYSTEM';
        }

        return (string)($context['role_level'] ?? '') === 'NATIONAL'
            && (string)($context['scope_type'] ?? '') === 'NATIONAL'
            && (string)($context['scope_mode'] ?? '') === 'NATIONAL';
    }

    /** @return array<string,mixed> */
    public static function assert(string $permission): array
    {
        if (!self::allowed($permission)) {
            throw new DomainException('Direct assignment editing requires an authorized Head Office working context.');
        }

        return Auth::activeContext(false) ?? throw new DomainException('Select an Active Working Context.');
    }

    /** @return array{role_assignment_id:string,scope_assignment_id:?string,role_code:string,role_level:string,scope_type:?string,scope_mode:?string,location_id:?string} */
    public static function auditContext(array $context): array
    {
        return [
            'role_assignment_id' => (string)$context['role_assignment_id'],
            'scope_assignment_id' => $context['scope_assignment_id'] === null ? null : (string)$context['scope_assignment_id'],
            'role_code' => (string)$context['role_code'],
            'role_level' => (string)$context['role_level'],
            'scope_type' => $context['scope_type'] === null ? null : (string)$context['scope_type'],
            'scope_mode' => $context['scope_mode'] === null ? null : (string)$context['scope_mode'],
            'location_id' => $context['location_id'] === null ? null : (string)$context['location_id'],
        ];
    }
}
