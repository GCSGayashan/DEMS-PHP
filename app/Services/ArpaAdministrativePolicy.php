<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth,Database};
use DomainException;

final class ArpaAdministrativePolicy
{
    public static function isCanonicalDemsAdmin():bool
    {
        $user=Auth::user();
        if($user===null)return false;
        $s=Database::pdo()->prepare("SELECT id FROM system_user WHERE username='dems.admin' AND enabled=1 AND account_status='ACTIVE' AND approval_status='APPROVED' LIMIT 1");
        $s->execute();$id=$s->fetchColumn();
        return $id!==false&&(string)$id===(string)$user['id'];
    }

    public static function canCorrectDates():bool
    {
        $context=Auth::activeContext(false);
        return $context!==null&&Auth::can('arpa.appointment.view')&&self::isCanonicalDemsAdmin();
    }

    /** @return array<string,mixed> */
    public static function assertDateCorrection():array
    {
        if(!self::canCorrectDates())throw new DomainException('Only the canonical dems.admin account may directly correct ARPA appointment dates.');
        return Auth::activeContext(false)??throw new DomainException('Select an Active Working Context.');
    }

    /** @return array<string,mixed>|null */
    public static function assertDelete():?array
    {
        if(!self::isCanonicalDemsAdmin())throw new DomainException('Only the canonical dems.admin account may delete an ARPA workflow request.');
        return Auth::activeContext(false);
    }

    /** @return array<string,mixed>|null */
    public static function auditContext(?array $context):?array
    {
        return $context===null?null:AssignmentDirectEditPolicy::auditContext($context);
    }
}
