<?php
declare(strict_types=1);
namespace App\Core;

final class WorkflowService
{
    private const TABLES=['office','officer','hr_title','appointment_nature','designation','officer_class','officer_status','civil_status'];

    private static function table(string $table): string
    {
        if(!in_array($table,self::TABLES,true)) throw new \InvalidArgumentException('Invalid workflow table');
        return $table;
    }

    public static function submit(string $table,string $id): void
    {
        $table=self::table($table); $user=Auth::user();
        $stmt=Database::pdo()->prepare("UPDATE {$table} SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND approval_status='DRAFT'");
        $stmt->execute([$user['id'],$id]);
        Audit::record('workflow.submit',strtoupper($table),$id);
    }

    public static function approve(string $table,string $id): void
    {
        $table=self::table($table);$pdo=Database::pdo();$user=Auth::user();
        $stmt=$pdo->prepare("SELECT created_by,effective_from,approval_status FROM {$table} WHERE id=?");$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row) throw new \RuntimeException('Record not found.');
        if((string)$row['created_by']===(string)$user['id']) throw new \RuntimeException('Maker cannot approve their own record.');
        if($row['approval_status']!=='SUBMITTED') throw new \RuntimeException('Only submitted records can be approved.');
        $extra=in_array($table,['office','officer'],true)?", operational_status=CASE WHEN effective_from<=CURRENT_DATE() THEN 'ACTIVE' ELSE 'INACTIVE' END":'';
        $pdo->prepare("UPDATE {$table} SET approval_status='APPROVED',approved_by=?,approved_at=NOW() {$extra} WHERE id=?")->execute([$user['id'],$id]);
        Audit::record('workflow.approve',strtoupper($table),$id);
    }

    public static function returnToDraft(string $table,string $id,string $reason): void
    {
        $table=self::table($table);$user=Auth::user();
        Database::pdo()->prepare("UPDATE {$table} SET approval_status='DRAFT',action_reason=? WHERE id=? AND approval_status='SUBMITTED'")->execute([$reason,$id]);
        Audit::record('workflow.return',strtoupper($table),$id,['reason'=>$reason]);
    }

    public static function withdraw(string $table,string $id,string $reason): void
    {
        $table=self::table($table);$user=Auth::user();
        if(in_array($table,['office','officer'],true)) {
            Database::pdo()->prepare("UPDATE {$table} SET approval_status='WITHDRAWN',withdrawn_by=?,withdrawn_at=NOW(),action_reason=? WHERE id=? AND approval_status='SUBMITTED'")->execute([$user['id'],$reason,$id]);
        } else {
            Database::pdo()->prepare("UPDATE {$table} SET approval_status='WITHDRAWN',action_reason=? WHERE id=? AND approval_status='SUBMITTED'")->execute([$reason,$id]);
        }
        Audit::record('workflow.withdraw',strtoupper($table),$id,['reason'=>$reason]);
    }
}
