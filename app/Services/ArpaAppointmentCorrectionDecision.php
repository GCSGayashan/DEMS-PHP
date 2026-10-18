<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/** Schema-aware helpers for reading the effective append-only correction decision. */
final class ArpaAppointmentCorrectionDecision
{
    public static function lineageAvailable(PDO $pdo):bool
    {
        try{
            $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction'
                  AND column_name IN('supersedes_correction_id','amendment_request_id')");
            if((int)$stmt->fetchColumn()!==2)return false;
            $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request'");
            return (int)$stmt->fetchColumn()===1;
        }catch(Throwable){return false;}
    }

    public static function effectivePredicate(PDO $pdo,string $alias):string
    {
        if(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/',$alias)!==1)throw new \DomainException('Invalid correction alias.');
        if(!self::lineageAvailable($pdo))return '1=1';
        return "NOT EXISTS(SELECT 1
            FROM arpa_appointment_data_correction successor_decision
            LEFT JOIN arpa_appointment_correction_amendment_request successor_amendment
              ON successor_amendment.id=successor_decision.amendment_request_id
            WHERE successor_decision.supersedes_correction_id={$alias}.id
              AND (successor_decision.amendment_request_id IS NULL
                OR (successor_amendment.workflow_status='NATIONAL_APPROVED'
                  AND successor_amendment.applied_correction_id=successor_decision.id)))";
    }
}
