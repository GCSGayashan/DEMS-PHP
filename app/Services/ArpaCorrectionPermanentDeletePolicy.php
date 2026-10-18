<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;

final class ArpaCorrectionPermanentDeletePolicy
{
    public static function allowed():bool
    {
        if(!ArpaAdministrativePolicy::isCanonicalDemsAdmin()||!Auth::can('arpa.appointment.view'))return false;
        $context=Auth::activeContext(false);
        return $context!==null
            &&(string)($context['role_code']??'')==='SYSTEM_ADMIN'
            &&(string)($context['role_level']??'')==='SYSTEM';
    }

    /** @return array<string,mixed> */
    public static function assert(string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||!self::allowed()){
            throw new DomainException('Only the canonical DEMS system administrator in the active System Administrator context may permanently delete an Appointment Data Correction decision.');
        }
        return Auth::activeContext(false)??throw new DomainException('Select an Active Working Context.');
    }
}
