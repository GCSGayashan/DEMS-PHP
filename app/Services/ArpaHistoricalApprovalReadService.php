<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,ScopeService};
use DomainException;
use PDO;

/** Shared authorized population for historical queues, summaries and dashboard counts. */
final class ArpaHistoricalApprovalReadService
{
    public function __construct(private readonly PDO $pdo){}
    public function available():bool
    {
        $s=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event')");
        return (int)$s->fetchColumn()===2;
    }
    public function canUse(string $actor):bool
    {
        return Auth::isCurrentUser($actor)&&Auth::activeContext(false)!==null&&Auth::can('arpa.appointment.view')
            &&($this->ownerContext()||(new ArpaWorkflowQueuePolicy($this->pdo))->profiles($actor)!==[]);
    }
    private function ownerContext():bool
    {
        $c=Auth::activeContext(false);
        return $c!==null&&$c['role_code']==='ASC_SUBJECT_OFFICER'&&$c['role_level']==='ASC'
            &&$c['scope_type']==='ASC'&&$c['scope_mode']==='EXACT'&&Auth::can('arpa.appointment.submit');
    }
    /** SQL uses the same Working Context scope CTE as the established native queue. */
    public function access(string $actor,string $mode):array
    {
        if(!in_array($mode,['pending','completed','read'],true)||!$this->canUse($actor))throw new DomainException('Historical approval access is not authorized.',403);
        $profiles=(new ArpaWorkflowQueuePolicy($this->pdo))->profiles($actor);$system=ScopeService::scopeProfile($actor)['level']==='SYSTEM';
        $needsScope=!$system&&array_filter($profiles,static fn($p)=>$p['stage']!=='NATIONAL')!==[];
        $with=$needsScope?ScopeService::arpaWorkflowScopeCte($actor):'';$params=$needsScope?ScopeService::arpaWorkflowScopeParams($actor):[];$where=[];
        if($mode!=='completed'&&$this->ownerContext()){
            $where[]="(hc.asc_location_id=?".($mode==='pending'?" AND hc.workflow_status IN('DRAFT','RETURNED')":'').')';$params[]=(string)Auth::activeContext(false)['location_id'];
        }
        foreach($profiles as $p){
            $stage=$p['stage'];$scope=($system||$stage==='NATIONAL')?'1=1':"EXISTS(SELECT 1 FROM workflow_scope_locations hs WHERE hs.stage='{$stage}' AND hs.id=hc.asc_location_id)";
            if($mode!=='completed'){
                $canAct='1=1';
                if($p['action']==='APPROVE'&&!Auth::can('arpa.appointment.return')&&!Auth::can('arpa.appointment.reject')){
                    $canAct="hc.created_by<>? AND COALESCE(hc.submitted_by,'')<>? AND dc.corrected_by<>? AND NOT EXISTS(SELECT 1 FROM arpa_appointment_historical_approval_event own_verify WHERE own_verify.approval_case_id=hc.id AND own_verify.stage='{$stage}' AND own_verify.action='VERIFY' AND own_verify.user_id=?)";
                    array_push($params,$actor,$actor,$actor,$actor);
                }
                $where[]="(hc.workflow_status='{$p['status']}' AND {$scope} AND {$canAct})";
            }
            if($mode!=='pending'){
                $event=$mode==='completed'?'he':'past';
                $review="({$event}.action='{$p['action']}' OR ({$event}.action IN('RETURN_FOR_CORRECTION','REJECT') AND {$event}.previous_status='{$p['status']}'))";
                if($stage==='ASC'&&$p['action']==='APPROVE')$review.=" OR ({$event}.action='REJECT' AND {$event}.previous_status IN('DRAFT','RETURNED'))";
                $condition="{$event}.user_id=? AND {$event}.stage='{$stage}' AND ({$review}) AND {$scope}";$params[]=$actor;
                $where[]=$mode==='completed'?'('.$condition.')':"EXISTS(SELECT 1 FROM arpa_appointment_historical_approval_event past WHERE past.approval_case_id=hc.id AND {$condition})";
            }
        }
        $retirementIds=$mode==='completed'?[]:(new ArpaHistoricalAppointmentApprovalService($this->pdo))->staleDraftRetirementIds($actor);
        if($retirementIds!==[]){$where[]="(hc.workflow_status IN('DRAFT','RETURNED') AND hc.id IN(".implode(',',array_fill(0,count($retirementIds),'?')).'))';array_push($params,...$retirementIds);}
        return ['with'=>$with,'params'=>$params,'where'=>$where===[]?'1=0':'('.implode(' OR ',$where).')','retirement_ids'=>$retirementIds];
    }
    public function query(string $actor,bool $completed=false,array $context=[]):array
    {
        if(!$this->available())throw new DomainException('Historical approval schema is unavailable.',503);
        $access=$this->access($actor,$completed?'completed':'pending');$where=[$access['where']];$params=$access['params'];
        foreach(['asc_id','district_id'] as $key){
            $value=trim((string)($context[$key]??''));if($value==='')continue;
            if(!preg_match('/^[a-f0-9-]{36}$/iD',$value)||!ScopeService::canAccessLocation($actor,$value))throw new DomainException('The geographic filter is outside your Working Context.',403);
            if($key==='asc_id'){$where[]='hc.asc_location_id=?';$params[]=$value;}
            else{$where[]="EXISTS(SELECT 1 FROM location_relationship dr WHERE dr.child_location_id=hc.asc_location_id AND dr.parent_location_id=? AND dr.relationship_type='DISTRICT_ASC' AND dr.active=1 AND dr.approval_status='APPROVED' AND dr.effective_from<=CURRENT_DATE() AND (dr.effective_to IS NULL OR dr.effective_to>=CURRENT_DATE()))";$params[]=$value;}
        }
        $eventJoin=$completed?'JOIN arpa_appointment_historical_approval_event he ON he.approval_case_id=hc.id':'';
        // IDs come only from the authorized backend assessment, not request filters.
        $entryType=$access['retirement_ids']===[]?"'Historical Appointment'":"CASE WHEN hc.id IN(".implode(',',array_map(fn(string $id):string=>$this->pdo->quote($id),$access['retirement_ids'])).") THEN 'Historical Appointment - Administrative resolution required (stale source)' ELSE 'Historical Appointment' END";
        $sql=$access['with']." SELECT ".($completed?'CAST(he.id AS CHAR)':'hc.id')." row_id,hc.id case_id,hc.appointment_id,hc.request_id,hc.source_correction_id,hc.officer_id,hc.asc_location_id,
            {$entryType} entry_type,o.dad_number officer_number,o.name_with_initials officer_name,a.appointment_type,al.name_en asc_name,arpa.name_en arpa_name,
            a.effective_from,cl.effective_to,r.workflow_status original_workflow_status,hc.workflow_status historical_status,hc.reason,hc.evidence_reference,
            dc.correction_action,dc.correction_reason,dc.evidence_reference correction_evidence,
            CASE WHEN cl.effective_to<CURRENT_DATE() THEN 'Ended' WHEN a.effective_from>CURRENT_DATE() THEN 'Scheduled' ELSE 'Current / Open' END operational_status,".
            ($completed?'he.action event_action,he.stage event_stage,he.new_status resulting_status,he.action_at':'NULL event_action,NULL event_stage,NULL resulting_status,hc.created_at action_at')."
            FROM arpa_appointment_historical_approval_case hc {$eventJoin}
            JOIN arpa_division_appointment_request r ON r.id=hc.request_id JOIN arpa_division_appointment a ON a.id=hc.appointment_id
            JOIN arpa_appointment_data_correction dc ON dc.id=hc.source_correction_id JOIN officer o ON o.id=hc.officer_id
            JOIN location al ON al.id=hc.asc_location_id JOIN location arpa ON arpa.id=a.arpa_division_location_id
            LEFT JOIN arpa_division_appointment_closure cl ON cl.appointment_id=a.id WHERE ".implode(' AND ',$where);
        return ['sql'=>$sql,'params'=>$params];
    }
    public function count(string $actor,bool $completed=false,array $context=[]):int
    {
        if(!$this->available()||!$this->canUse($actor))return 0;
        $q=$this->query($actor,$completed,$context);$s=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$q['sql'].') hist_count');$s->execute($q['params']);return (int)$s->fetchColumn();
    }
    /** Status enrichment is set-based over the already-authorized appointment collection. */
    public function profileRows(array $appointmentIds,string $actor):array
    {
        if($appointmentIds===[]||!Auth::isCurrentUser($actor)||!$this->available())return [];
        $access=$this->canUse($actor)?$this->access($actor,'read'):['with'=>'','params'=>[],'where'=>'1=0'];
        $marks=implode(',',array_fill(0,count($appointmentIds),'?'));
        $sql=$access['with']." SELECT hc.id,hc.appointment_id,hc.workflow_status,hc.created_at,dc.correction_action,dc.correction_reason,dc.evidence_reference,({$access['where']}) can_read_case
            FROM arpa_appointment_historical_approval_case hc JOIN arpa_appointment_data_correction dc ON dc.id=hc.source_correction_id
            WHERE hc.appointment_id IN({$marks}) ORDER BY (hc.active_appointment_id IS NOT NULL) DESC,hc.created_at DESC,hc.id DESC";
        $s=$this->pdo->prepare($sql);$s->execute(array_merge($access['params'],array_values($appointmentIds)));$out=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['appointment_id']][]=$r;return $out;
    }
}
