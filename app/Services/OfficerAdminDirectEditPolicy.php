<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;

final class OfficerAdminDirectEditPolicy
{
    public static function allowed():bool
    {
        if(!ArpaAdministrativePolicy::isCanonicalDemsAdmin())return false;
        $context=Auth::activeContext(false);
        return $context!==null
            &&(string)($context['role_code']??'')==='SYSTEM_ADMIN'
            &&(string)($context['role_level']??'')==='SYSTEM';
    }

    /** @return array<string,mixed> */
    public static function assert(string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||!self::allowed()){
            throw new DomainException('Only the canonical dems.admin account in its System Administrator context may directly edit an approved Officer.');
        }
        return Auth::activeContext(false)??throw new DomainException('Select an Active Working Context.');
    }

    /** @return array<string,mixed> */
    public static function auditContext(array $context):array
    {
        return AssignmentDirectEditPolicy::auditContext($context);
    }
}
