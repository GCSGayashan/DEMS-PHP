<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth,Database};
use DomainException;

final class AssignmentDeletePolicy
{
    public static function allowed():bool
    {
        $user=Auth::user();
        if($user===null)return false;
        $stmt=Database::pdo()->prepare("SELECT id FROM system_user WHERE username='dems.admin' AND enabled=1 AND account_status='ACTIVE' AND approval_status='APPROVED' LIMIT 1");
        $stmt->execute();$canonical=$stmt->fetchColumn();
        return $canonical!==false&&(string)$canonical===(string)$user['id'];
    }

    /** @return array<string,mixed>|null */
    public static function assert(?string $actorId=null):?array
    {
        if(!self::allowed()||($actorId!==null&&!Auth::isCurrentUser($actorId)))throw new DomainException('Only the DEMS operational administrator may delete assignments.');
        return Auth::activeContext(false);
    }

    /** @return array<string,mixed>|null */
    public static function auditContext(?array $context):?array
    {
        return $context===null?null:AssignmentDirectEditPolicy::auditContext($context);
    }
}
