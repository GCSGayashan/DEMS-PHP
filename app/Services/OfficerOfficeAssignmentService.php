<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\ScopeService;
use DomainException;
use PDO;
use Throwable;

final class OfficerOfficeAssignmentService
{
    public const INITIAL_OFFICER_REASON='Initial Office assignment selected during Officer creation';

    public function __construct(private readonly PDO $pdo) {}

    public function saveInitialForOfficer(string $officerId,?string $officeId,?string $effectiveFrom,string $actorId):?string
    {
        $officeId=trim((string)$officeId);$effectiveFrom=trim((string)$effectiveFrom);
        return $this->transaction(function()use($officerId,$officeId,$effectiveFrom,$actorId):?string{
            $officer=$this->pdo->prepare('SELECT id,approval_status,created_by FROM officer WHERE id=? FOR UPDATE');$officer->execute([$officerId]);$officerRow=$officer->fetch();
            if(!$officerRow||!in_array((string)$officerRow['approval_status'],['DRAFT','SUBMITTED'],true))throw new DomainException('The initial Office can be changed only while the Officer is awaiting approval or correction.');
            if((string)$officerRow['created_by']!==$actorId)throw new DomainException('Only the Officer maker may select the initial Office.');
            $existing=$this->lockedInitialForOfficer($officerId);
            if($officeId==='')return $existing['id']??null;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveFrom))throw new DomainException('Select a valid Office Assignment Effective From date.');
            if(!ScopeService::canAccessOffice($actorId,$officeId))throw new DomainException('You cannot select this Office.');
            $this->assertActiveOffice($officeId);
            $existingId=(string)($existing['id']??'');$dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE id<>? AND officer_id=? AND office_id=? AND active=1 AND approval_status IN('DRAFT','SUBMITTED','APPROVED','RETURNED') AND (effective_to IS NULL OR effective_to>=?)");$dup->execute([$existingId,$officerId,$officeId,$effectiveFrom]);
            if((int)$dup->fetchColumn()>0)throw new DomainException('An overlapping Office assignment already exists for this Officer and Office.');
            $primary=$effectiveFrom<=date('Y-m-d')?1:0;
            if($existing){
                if(!in_array((string)$existing['approval_status'],['DRAFT','RETURNED'],true))throw new DomainException('The submitted initial Office assignment must be returned before it can be changed.');
                $before=$existing;$this->pdo->prepare('UPDATE officer_office_assignment SET office_id=?,effective_from=?,is_primary=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$officeId,$effectiveFrom,$primary,$actorId,$existing['id']]);
                $this->event((string)$existing['id'],'UPDATED',$before,$this->row((string)$existing['id']),'Initial Office corrected with the returned Officer.',$actorId);return (string)$existing['id'];
            }
            $id=$this->uuid();$status=(string)$officerRow['approval_status'];$submitted=$status==='SUBMITTED';
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,reason,remarks,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,IF(?,NOW(),NULL))")
                ->execute([$id,$officerId,$officeId,$effectiveFrom,$primary,self::INITIAL_OFFICER_REASON,'Created with the Officer submission.',$status,$actorId,$submitted?$actorId:null,$submitted?1:0]);
            $this->event($id,$status,null,$this->row($id),self::INITIAL_OFFICER_REASON,$actorId);return $id;
        });
    }

    public function initialForOfficer(string $officerId):?array
    {
        $rows=$this->initialRows($officerId,false);if(count($rows)>1)throw new DomainException('The Officer has multiple initial Office assignments and requires review.');return $rows[0]??null;
    }

    public function returnInitialForCorrection(string $officerId,string $reason,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);if(!$assignment)return;
        $this->transaction(function()use($assignment,$reason,$actorId):void{
            $row=$this->locked((string)$assignment['id']);if($row['approval_status']!=='SUBMITTED')throw new DomainException('The initial Office assignment is not awaiting approval.');if($row['created_by']===$actorId||$row['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-review.');$this->assertScope($row,$actorId);$before=$row;
            $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='RETURNED',updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?")->execute([$actorId,$row['id']]);$this->event((string)$row['id'],'RETURNED',$before,$this->row((string)$row['id']),$reason,$actorId);
        });
    }

    public function submitInitialForOfficer(string $officerId,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);if(!$assignment||$assignment['approval_status']==='SUBMITTED')return;$this->submit((string)$assignment['id'],$actorId);
    }

    public function approveInitialForOfficer(string $officerId,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);if(!$assignment)return;$this->approve((string)$assignment['id'],$actorId);
    }

    public function create(array $data,string $actorId):string
    {
        $officer=trim((string)($data['officer_id']??''));$office=trim((string)($data['office_id']??''));
        $from=trim((string)($data['effective_from']??''));$reason=trim((string)($data['reason']??''));
        if($officer===''||$office===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||$reason==='')throw new DomainException('Officer, Office, Start Date, and Reason are required.');
        if(!ScopeService::canAccessOffice($actorId,$office))throw new DomainException('You cannot select this Office.');
        $this->assertEntity('officer',$officer);$this->assertActiveOffice($office);
        return $this->transaction(function()use($data,$actorId,$officer,$office,$from,$reason):string{
            $dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=? AND office_id=? AND active=1 AND approval_status IN('DRAFT','SUBMITTED','APPROVED') AND effective_from=?");$dup->execute([$officer,$office,$from]);
            if((int)$dup->fetchColumn()>0)throw new DomainException('An Office assignment already exists for this Officer, Office and effective date.');
            $id=$this->uuid();$primary=!empty($data['is_primary'])?1:0;
            if($primary===1&&$from>date('Y-m-d'))throw new DomainException('A future Office assignment can be set as Primary when it becomes effective.');
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,reason,official_reference,remarks,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,?,'SUBMITTED',?,?,NOW())")
                ->execute([$id,$officer,$office,$from,$primary,$reason,$this->null($data['official_reference']??null),$this->null($data['remarks']??null),$actorId,$actorId]);
            $this->event($id,'SUBMITTED',null,$this->row($id),$reason,$actorId);return $id;
        });
    }

    public function submit(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='DRAFT'&&$r['approval_status']!=='RETURNED')throw new DomainException('Only a draft or returned Office assignment may be submitted.');if($r['created_by']!==$actorId)throw new DomainException('Only the creator may submit this Office assignment.');$this->assertScope($r,$actorId);$before=$r;$this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$actorId,$actorId,$id]);$this->event($id,'SUBMITTED',$before,$this->row($id),null,$actorId);});
    }

    public function approve(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Office assignment may be approved.');if($r['created_by']===$actorId||$r['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-approval.');$this->assertScope($r,$actorId);$lock=$this->pdo->prepare('SELECT id FROM officer WHERE id=? FOR UPDATE');$lock->execute([$r['officer_id']]);$lock=$this->pdo->prepare('SELECT id FROM office WHERE id=? FOR UPDATE');$lock->execute([$r['office_id']]);$this->assertNoOverlap($r);$before=$r;
            if((int)$r['is_primary']===1)$this->clearCurrentPrimary((string)$r['officer_id'],$id,$actorId);
            $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='APPROVED',approved_by=?,approved_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$actorId,$actorId,$id]);
            if((int)$r['is_primary']===1 && $r['effective_from']<=date('Y-m-d'))$this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$r['office_id'],$actorId,$r['officer_id']]);
            $this->event($id,'APPROVED',$before,$this->row($id),null,$actorId);
        });
    }

    public function end(string $id,string $effectiveTo,string $reason,string $actorId):void
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveTo)||trim($reason)==='')throw new DomainException('End Date and Reason are required.');
        $this->transaction(function()use($id,$effectiveTo,$reason,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='APPROVED'||!(int)$r['active'])throw new DomainException('Only an approved active Office assignment may be ended.');if($effectiveTo<$r['effective_from'])throw new DomainException('Effective To cannot precede Effective From.');$this->assertScope($r,$actorId);$before=$r;$this->pdo->prepare('UPDATE officer_office_assignment SET effective_to=?,ended_by=?,ended_at=NOW(),updated_by=?,version=version+1 WHERE id=?')->execute([$effectiveTo,$actorId,$actorId,$id]);if((int)$r['is_primary']===1&&$effectiveTo<=date('Y-m-d'))$this->pdo->prepare('UPDATE officer SET primary_office_id=NULL,updated_by=?,version=version+1 WHERE id=? AND primary_office_id=?')->execute([$actorId,$r['officer_id'],$r['office_id']]);$this->event($id,'ENDED',$before,$this->row($id),$reason,$actorId);});
    }

    public function setPrimary(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);$today=date('Y-m-d');if($r['approval_status']!=='APPROVED'||!(int)$r['active']||$r['effective_from']>$today||($r['effective_to']!==null&&$r['effective_to']<$today))throw new DomainException('Only a current approved Office assignment may be primary.');$this->assertScope($r,$actorId);$before=$r;$this->clearCurrentPrimary((string)$r['officer_id'],$id,$actorId);$this->pdo->prepare('UPDATE officer_office_assignment SET is_primary=1,updated_by=?,version=version+1 WHERE id=?')->execute([$actorId,$id]);$this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$r['office_id'],$actorId,$r['officer_id']]);$this->event($id,'SET_PRIMARY',$before,$this->row($id),null,$actorId);});
    }

    public function hasCurrentAscOfficeAssignment(string $officerId,string $ascLocationId,string $date):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED'");$s->execute([$officerId,$ascLocationId,$date,$date]);return (int)$s->fetchColumn()>0;
    }

    public function hasCurrentAscOfficeAssignmentNow(string $officerId,string $ascLocationId):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED' AND o.effective_from<=CURRENT_DATE() AND (o.effective_to IS NULL OR o.effective_to>=CURRENT_DATE())");$s->execute([$officerId,$ascLocationId]);return (int)$s->fetchColumn()>0;
    }

    private function assertNoOverlap(array $r):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE id<>? AND officer_id=? AND office_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=COALESCE(?, '9999-12-31') AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE");$s->execute([$r['id'],$r['officer_id'],$r['office_id'],$r['effective_to'],$r['effective_from']]);if((int)$s->fetchColumn()>0)throw new DomainException('This Officer already has an overlapping approved assignment to the selected Office.');}
    private function lockedInitialForOfficer(string $officerId):?array{$rows=$this->initialRows($officerId,true);if(count($rows)>1)throw new DomainException('The Officer has multiple initial Office assignments and requires review.');return $rows[0]??null;}
    private function initialRows(string $officerId,bool $lock):array{$sql="SELECT a.*,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.officer_id=? AND a.reason=? ORDER BY a.created_at,a.id".($lock?' FOR UPDATE':'');$s=$this->pdo->prepare($sql);$s->execute([$officerId,self::INITIAL_OFFICER_REASON]);return $s->fetchAll();}
    private function clearCurrentPrimary(string $officerId,string $except,string $actorId):void{$s=$this->pdo->prepare("SELECT id FROM officer_office_assignment WHERE officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) FOR UPDATE");$s->execute([$officerId,$except]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$before=$this->row($id);$this->pdo->prepare('UPDATE officer_office_assignment SET is_primary=0,updated_by=?,version=version+1 WHERE id=?')->execute([$actorId,$id]);$this->event($id,'PRIMARY_REPLACED',$before,$this->row($id),null,$actorId);}}
    private function assertScope(array $r,string $actor):void{if(!ScopeService::canAccessOffice($actor,(string)$r['office_id']))throw new DomainException('You cannot manage this Office assignment.');}
    private function assertActiveOffice(string $id):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM office WHERE id=? AND approval_status='APPROVED' AND operational_status='ACTIVE'");$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('The selected Office is not approved and active.');}
    private function assertEntity(string $table,string $id):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=?");$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('Officer was not found.');}
    private function locked(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer_office_assignment WHERE id=? FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Office assignment was not found.');return $r;}
    private function row(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer_office_assignment WHERE id=?');$s->execute([$id]);return $s->fetch()?:[];}
    private function event(string $id,string $action,?array $before,array $after,?string $reason,string $actor):void{$this->pdo->prepare('INSERT INTO officer_office_assignment_audit(assignment_id,action_key,previous_state_json,new_state_json,reason,actor_user_id) VALUES(?,?,?,?,?,?)')->execute([$id,$action,$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,json_encode($after,JSON_UNESCAPED_UNICODE),$reason,$actor]);}
    private function null(mixed $v):?string{$v=trim((string)$v);return $v===''?null:$v;}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $fn):mixed{$own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();try{$r=$fn();if($own)$this->pdo->commit();return $r;}catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
