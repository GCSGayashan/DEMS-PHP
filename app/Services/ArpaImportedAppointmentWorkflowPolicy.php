<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,ScopeService};
use DateTimeImmutable;
use DomainException;
use PDO;

/** Authorization of an existing imported period, never appointment creation. */
final class ArpaImportedAppointmentWorkflowPolicy
{
    public const BLOCKED='Imported appointment requires an unambiguous existing Permanent appointment at District/National review. No appointment data was changed.';
    public const ORIGINAL_REVIEW_STATUSES=['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED'];
    public function __construct(private readonly PDO $pdo){}
    public static function usesOriginalReview(array $request):bool
    {
        return ($request['record_origin']??'NATIVE')==='LEGACY_IMPORT'
            &&in_array($request['workflow_status']??'',self::ORIGINAL_REVIEW_STATUSES,true);
    }
    public function assertReviewer(array $request,string $actor,string $action,string $stage):void
    {
        if(!self::usesOriginalReview($request)||($request['request_type']??'')!=='APPOINTMENT'||($request['appointment_type']??'')!=='PERMANENT')throw new DomainException(self::BLOCKED,409);
        $step=ArpaWorkflowQueuePolicy::reviewStep((string)$request['workflow_status']);
        if(!Auth::isCurrentUser($actor)||Auth::activeContext(false)===null||ArpaAdministrativePolicy::isCanonicalSystemAdministrator()
            ||$step===null||!in_array($step['stage'],['DISTRICT','NATIONAL'],true)||$action!==$step['action']||$stage!==$step['stage']
            ||!Auth::can($step['permission'])||!ScopeService::canAccessCurrentArpaStage($actor,$stage,(string)$request['asc_location_id']))throw new DomainException('The imported original-workflow action is outside your current stage, permission or Working Context.',403);
    }
    public function period(array $request,bool $lock=false):array
    {
        if(!self::usesOriginalReview($request)||($request['request_type']??'')!=='APPOINTMENT'||($request['appointment_type']??'')!=='PERMANENT'
            ||(int)($request['legacy_history_only']??1)!==0||($request['deleted_at']??null)!==null)throw new DomainException(self::BLOCKED,409);
        $suffix=$lock?' FOR UPDATE':'';
        if($lock){foreach(['officer'=> $request['officer_id'],'location'=>$request['arpa_division_location_id']] as $table=>$id){$s=$this->pdo->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new DomainException(self::BLOCKED,409);}}
        $s=$this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE request_id=?'.$suffix);$s->execute([$request['id']]);$rows=$s->fetchAll();
        if(count($rows)!==1)throw new DomainException(self::BLOCKED,409);$a=$rows[0];
        foreach(['officer_id','asc_location_id','arpa_division_location_id','appointment_type'] as $field)if($a[$field]!==$request[$field])throw new DomainException(self::BLOCKED,409);
        if($a['record_origin']!=='LEGACY_IMPORT'||(int)$a['legacy_history_only']!==0||$a['effective_from']!==$request['requested_effective_from']
            ||(!empty($request['source_appointment_id'])&&$request['source_appointment_id']!==$a['id']))throw new DomainException(self::BLOCKED,409);
        $s=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=?'.$suffix);$s->execute([$a['id']]);$closures=$s->fetchAll();
        if(count($closures)>1)throw new DomainException(self::BLOCKED,409);$closure=$closures[0]??null;$end=$closure['effective_to']??null;
        // No closure is an open period only when the authoritative request has no ending information.
        if($closure===null&&(($request['requested_effective_to']??null)!==null||($request['end_reason_id']??null)!==null))throw new DomainException(self::BLOCKED,409);
        foreach($closure===null?[$a['effective_from']]:[$a['effective_from'],$end] as $date){
            if(!is_string($date)||$date==='')throw new DomainException(self::BLOCKED,409);
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException(self::BLOCKED,409);
        }
        if($end!==null&&($end<$a['effective_from']||(($request['requested_effective_to']??null)!==null&&$request['requested_effective_to']!==$end)))throw new DomainException(self::BLOCKED,409);
        if($closure!==null&&($request['end_reason_id']??null)!==null&&$request['end_reason_id']!==$closure['end_reason_id'])throw new DomainException(self::BLOCKED,409);
        if($closure!==null&&$closure['request_id']!==null&&$closure['request_id']!==$request['id']){
            $s=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=?'.$suffix);$s->execute([$closure['request_id']]);$ends=$s->fetchAll();
            if(count($ends)!==1)throw new DomainException(self::BLOCKED,409);$endRequest=$ends[0];
            if(!in_array($endRequest['request_type'],['END','TRANSFER'],true)||$endRequest['source_appointment_id']!==$a['id']
                ||$endRequest['officer_id']!==$a['officer_id']||$endRequest['requested_effective_to']!==$end||$endRequest['deleted_at']!==null
                ||(($endRequest['end_reason_id']??null)!==null&&$endRequest['end_reason_id']!==$closure['end_reason_id']))throw new DomainException(self::BLOCKED,409);
        }
        $parents=(new ArpaAppointmentLocationPolicy())->parents($this->pdo,$a['arpa_division_location_id'],'ASC_ARPA_DIVISION',$a['effective_from']);
        if(count($parents)!==1||$parents[0]['id']!==$a['asc_location_id'])throw new DomainException('The imported appointment hierarchy is ambiguous or inconsistent.',409);
        // Reuse the complete-period checks used for historical authorization; the native creation baseline remains unchanged.
        (new ArpaDivisionContinuityService($this->pdo))->assertNoUnresolvedDataIssues($a['arpa_division_location_id'],$lock);
        $read=new ArpaAppointmentReadService($this->pdo);
        $read->assertDivisionPeriodDoesNotOverlap($a['arpa_division_location_id'],$a['effective_from'],$end,false,$request['id'],$a['id']);
        $read->assertAppointmentTypeAvailable($a['officer_id'],$a['appointment_type'],$a['arpa_division_location_id'],$a['effective_from'],$end,$request['id'],$a['id']);
        return ['appointment_id'=>$a['id'],'effective_from'=>$a['effective_from'],'effective_to'=>$end,'closure_id'=>$closure['id']??null];
    }
}
