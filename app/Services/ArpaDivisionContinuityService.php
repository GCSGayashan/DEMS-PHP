<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DomainException;
use PDO;

final class ArpaDivisionContinuityService
{
    public const BASELINE='2025-01-01';

    public function __construct(private readonly PDO $pdo) {}

    /** @param list<string> $divisionIds @return array<string,array<string,mixed>> */
    public function requirements(array $divisionIds,string $proposedStart,?string $excludeRequestId=null,?string $excludeAppointmentId=null):array
    {
        ArpaAppointmentRules::assertNativeEffectiveDate($proposedStart);
        $divisionIds=array_values(array_unique(array_filter(array_map('strval',$divisionIds))));
        if($divisionIds===[])return [];
        $marks=implode(',',array_fill(0,count($divisionIds),'?'));
        $statuses=implode(',',array_fill(0,count(ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES),'?'));
        $sql="SELECT a.arpa_division_location_id division_id,a.id source_id,a.effective_from,
                     c.effective_to,'OPERATIONAL' source_kind
              FROM arpa_division_appointment a
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              WHERE a.arpa_division_location_id IN({$marks}) AND a.id<>COALESCE(?, '')
                AND (a.legacy_history_only=0 OR c.id IS NOT NULL)
                AND a.effective_from IS NOT NULL
                AND (a.effective_from>=? OR c.effective_to IS NULL OR c.effective_to>=?)
              UNION ALL
              SELECT r.arpa_division_location_id,r.id,r.requested_effective_from,
                     CASE WHEN r.request_type='TRANSFER' THEN NULL ELSE r.requested_effective_to END,'RESERVATION'
              FROM arpa_division_appointment_request r
              WHERE r.arpa_division_location_id IN({$marks}) AND r.id<>COALESCE(?, '')
                AND r.record_origin='NATIVE' AND r.legacy_history_only=0
                AND r.request_type IN('APPOINTMENT','TRANSFER')
                AND r.workflow_status IN({$statuses})
                AND r.requested_effective_from IS NOT NULL
                AND (r.requested_effective_from>=? OR r.request_type='TRANSFER' OR r.requested_effective_to IS NULL OR r.requested_effective_to>=?)";
        $params=array_merge(
            $divisionIds,[$excludeAppointmentId,self::BASELINE,self::BASELINE],
            $divisionIds,[$excludeRequestId],ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES,[self::BASELINE,self::BASELINE]
        );
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$byDivision=[];
        foreach($stmt->fetchAll() as $row)$byDivision[(string)$row['division_id']][]=$row;

        $result=[];
        foreach($divisionIds as $divisionId){
            $diagnostic=$this->calculate($byDivision[$divisionId]??[],$proposedStart);
            $diagnostic['unresolved_data_issue_count']=0;
            $diagnostic['needs_action']=$diagnostic['timeline_status']!=='COMPLETE';
            $result[$divisionId]=$diagnostic;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function requirement(string $divisionId,string $proposedStart,?string $excludeRequestId=null,?string $excludeAppointmentId=null):array
    {
        return $this->requirements([$divisionId],$proposedStart,$excludeRequestId,$excludeAppointmentId)[$divisionId];
    }

    /** @return array<string,mixed> */
    public function assertCanStart(
        string $divisionId,
        string $proposedStart,
        ?string $excludeRequestId=null,
        ?string $excludeAppointmentId=null,
        bool $checkDataIssues=true,
        bool $lock=true
    ):array {
        if($lock)$this->lockDivision($divisionId);
        if($checkDataIssues)$this->assertNoUnresolvedDataIssues($divisionId,false);
        $requirement=$this->requirement($divisionId,$proposedStart,$excludeRequestId,$excludeAppointmentId);
        $statuses=(array)($requirement['timeline_statuses']??[]);
        if(in_array('INVALID_PERIOD',$statuses,true))throw new DomainException('This ARPA Division has an invalid authoritative assignment period. Resolve the Appointment Data Issue before creating a new request.');
        if(in_array('MULTIPLE_OPEN_ASSIGNMENTS',$statuses,true))throw new DomainException('This ARPA Division has multiple Open assignments. Resolve the Appointment Data Issue before creating a new request.');
        if(in_array('OVERLAP',$statuses,true))throw new DomainException('This ARPA Division has overlapping authoritative assignment periods. Resolve the timeline before creating a new request.');
        if($requirement['relation']==='GAP'){
            if((int)$requirement['authoritative_period_count']===0){
                throw new DomainException('This ARPA Division has no assignment history from 01 Jan 2025. Complete the missing period starting 01 Jan 2025 first.');
            }
            throw new DomainException('This ARPA Division has an uncovered assignment period. The next assignment must start on '.$this->displayDate((string)$requirement['required_next_start']).'.');
        }
        if($requirement['relation']==='OVERLAP'){
            throw new DomainException('The proposed start date overlaps an existing authoritative ARPA Division assignment period.');
        }
        return $requirement;
    }

    /**
     * Validate a complete missing period. A bounded historical gap must be
     * filled through the day immediately before the next authoritative record;
     * an unbounded final gap must remain open.
     *
     * @return array<string,mixed>
     */
    public function assertCanFillPeriod(
        string $divisionId,
        string $proposedStart,
        ?string $proposedEnd=null,
        ?string $excludeRequestId=null,
        ?string $excludeAppointmentId=null,
        bool $checkDataIssues=true,
        bool $lock=true
    ):array {
        $requirement=$this->assertCanStart($divisionId,$proposedStart,$excludeRequestId,$excludeAppointmentId,$checkDataIssues,$lock);
        $maximumEnd=$requirement['maximum_end_date'];
        if($maximumEnd!==null&&$proposedEnd===null){
            throw new DomainException('This historical gap is bounded by a later assignment. The new assignment must end on '.$this->displayDate((string)$maximumEnd).'.');
        }
        if($maximumEnd!==null&&$proposedEnd!==$maximumEnd){
            throw new DomainException('To preserve continuous history, this assignment must end on '.$this->displayDate((string)$maximumEnd).'.');
        }
        if($maximumEnd===null&&$proposedEnd!==null){
            throw new DomainException('This is the final uncovered period. The new assignment must remain open unless it is later ended through the normal workflow.');
        }
        return $requirement;
    }

    public function assertNoUnresolvedDataIssues(string $divisionId,bool $lock=true):void
    {
        if($lock)$this->lockDivision($divisionId);
        if($this->unresolvedDataIssues($divisionId)!==[])throw new DomainException('This ARPA Division has unresolved Appointment Data Issues. Review and complete them before creating a new appointment request.');
    }

    /** @return array<string,mixed>|null */
    public function blockingDataIssue(string $divisionId,array $requirement,string $proposedStart):?array
    {
        return $this->unresolvedDataIssues($divisionId)[0]??null;
    }

    /** Any unresolved issue for a Division blocks normal assignment creation until terminally completed. */
    public function unresolvedDataIssues(string $divisionId):array
    {
        $rows=array_merge($this->canonicalIssues($divisionId),$this->reconciliationIssues($divisionId));$unique=[];
        foreach($rows as $row)$unique[(string)($row['row_key']??$row['reconciliation_item_id'])]=$row;
        return array_values($unique);
    }

    /** @return array<int,array<string,mixed>> */
    public function invalidPendingAssignments():array
    {
        $statuses=implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES);
        $sql="SELECT r.id,r.workflow_status,r.requested_effective_from,r.arpa_division_location_id,
                     l.dad_number division_dad_number,l.name_en division_name,o.dad_number officer_dad_number,
                     o.name_with_initials officer_name
              FROM arpa_division_appointment_request r
              JOIN location l ON l.id=r.arpa_division_location_id
              JOIN officer o ON o.id=r.officer_id
              WHERE r.record_origin='NATIVE' AND r.legacy_history_only=0
                AND r.request_type IN('APPOINTMENT','TRANSFER')
                AND r.workflow_status IN('{$statuses}') AND r.requested_effective_from IS NOT NULL
              ORDER BY l.name_en,r.requested_effective_from,r.id";
        $rows=[];
        foreach($this->pdo->query($sql)->fetchAll() as $request){
            $requirement=$this->requirement((string)$request['arpa_division_location_id'],(string)$request['requested_effective_from'],(string)$request['id']);
            if($requirement['relation']!=='GAP')continue;
            $issue=$this->blockingDataIssue((string)$request['arpa_division_location_id'],$requirement,(string)$request['requested_effective_from']);
            $reportedGapEnd=(new DateTimeImmutable((string)$request['requested_effective_from']))->modify('-1 day')->format('Y-m-d');
            $rows[]=$request+[
                'last_covered_through'=>$requirement['last_covered_through'],
                'required_next_start'=>$requirement['required_next_start'],
                'gap_start'=>$requirement['required_next_start'],
                'gap_end'=>$reportedGapEnd,
                'unresolved_data_issue'=>$issue!==null,
                'data_issue_key'=>$issue['row_key']??$issue['reconciliation_item_id']??null,
                'data_issue_type'=>$issue['issue_type']??null,
            ];
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $periods @return array<string,mixed> */
    private function calculate(array $periods,string $proposedStart):array
    {
        usort($periods,static fn(array $a,array $b):int=>[(string)$a['effective_from'],(string)($a['effective_to']??'9999-12-31'),(string)$a['source_id']]<=>[(string)$b['effective_from'],(string)($b['effective_to']??'9999-12-31'),(string)$b['source_id']]);
        $cursor=self::BASELINE;$gaps=[];$overlaps=[];$invalid=[];$openCount=0;$valid=[];$coverage=[];
        foreach($periods as $period){
            $rawStart=(string)$period['effective_from'];$rawEnd=$period['effective_to']===null?null:(string)$period['effective_to'];
            if($rawEnd!==null&&$rawEnd<$rawStart){$invalid[]=$period;continue;}
            if($rawEnd!==null&&$rawEnd<self::BASELINE)continue;
            if($rawEnd===null)$openCount++;
            $start=max(self::BASELINE,$rawStart);$end=$rawEnd??'9999-12-31';$period['effective_from']=$start;$period['effective_to']=$rawEnd;$valid[]=$period;
            if($cursor==='9999-12-31'){$overlaps[]=$period;continue;}
            if($start>$cursor){
                $gaps[]=['gap_start'=>$cursor,'gap_end'=>(new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d'),'next_existing_start'=>$start,'next_existing_end'=>$rawEnd];
                $coverage[]=['effective_from'=>$start,'effective_to'=>$rawEnd];
            }elseif($start<$cursor){
                $overlaps[]=$period;
            }else{
                $coverage[]=['effective_from'=>$start,'effective_to'=>$rawEnd];
            }
            if($end==='9999-12-31'){$cursor='9999-12-31';continue;}
            $next=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
            if($cursor!=='9999-12-31'&&$next>$cursor)$cursor=$next;
        }
        if($cursor!=='9999-12-31')$gaps[]=['gap_start'=>$cursor,'gap_end'=>null,'next_existing_start'=>null,'next_existing_end'=>null];

        $required=$gaps[0]['gap_start']??null;$maximumEnd=$gaps[0]['gap_end']??null;
        if($required===null){
            $relation='OVERLAP';
        }elseif($proposedStart===$required){
            $relation='EXACT';
        }elseif($proposedStart>$required&&($maximumEnd===null||$proposedStart<=$maximumEnd)){
            $relation='GAP';
        }else{
            $relation='OVERLAP';
        }
        $lastCovered=$required===null||$required===self::BASELINE?null:(new DateTimeImmutable($required))->modify('-1 day')->format('Y-m-d');
        $statuses=[];
        if($invalid!==[])$statuses[]='INVALID_PERIOD';
        if($openCount>1)$statuses[]='MULTIPLE_OPEN_ASSIGNMENTS';
        if($overlaps!==[])$statuses[]='OVERLAP';
        if($gaps!==[]){
            if($gaps[0]['gap_start']===self::BASELINE)$statuses[]='MISSING_BASELINE_PERIOD';
            elseif($gaps[0]['gap_end']!==null&&$openCount>0)$statuses[]='CURRENT_WITH_HISTORICAL_GAP';
            else $statuses[]='HISTORICAL_GAP';
        }
        if($statuses===[])$statuses[]='COMPLETE';
        return [
            'required_next_start'=>$required,
            'last_covered_through'=>$lastCovered,
            'last_authoritative_start'=>$valid===[]?null:(string)$valid[array_key_last($valid)]['effective_from'],
            'last_authoritative_end'=>$valid===[]?null:$valid[array_key_last($valid)]['effective_to'],
            'authoritative_period_count'=>count($valid),
            'relation'=>$relation,
            'gap_start'=>$gaps[0]['gap_start']??null,
            'gap_end'=>$maximumEnd,
            'maximum_end_date'=>$maximumEnd,
            'next_existing_start'=>$gaps[0]['next_existing_start']??null,
            'next_existing_end'=>$gaps[0]['next_existing_end']??null,
            'gaps'=>$gaps,
            'gap_count'=>count($gaps),
            'overlap_count'=>count($overlaps),
            'invalid_period_count'=>count($invalid),
            'open_assignment_count'=>$openCount,
            'timeline_status'=>$statuses[0],
            'timeline_statuses'=>$statuses,
            'coverage_segments'=>$coverage,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function canonicalIssues(string $divisionId):array
    {
        $source=ArpaAppointmentReadService::issueSource();
        $sql="SELECT q.row_key,q.issue_type,q.severity,q.officer_number,q.officer_name,q.appointment_types,q.effective_periods,
                     MIN(a.effective_from) issue_from,MAX(COALESCE(c.effective_to,'9999-12-31')) issue_to
              FROM {$source} q
              JOIN arpa_division_appointment a ON FIND_IN_SET(a.id,q.related_ids)>0
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              WHERE a.arpa_division_location_id=?
                AND NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc
                               WHERE dc.issue_row_key=q.row_key AND dc.resolution_status IN('RESOLVED_BY_CORRECTION','KEPT_HISTORICAL_EXCEPTION'))
              GROUP BY q.row_key,q.issue_type,q.severity,q.officer_number,q.officer_name,q.appointment_types,q.effective_periods
              UNION ALL
              SELECT q.row_key,q.issue_type,q.severity,q.officer_number,q.officer_name,q.appointment_types,q.effective_periods,
                     COALESCE(r.requested_effective_from,?) issue_from,COALESCE(r.requested_effective_to,'9999-12-31') issue_to
              FROM {$source} q JOIN arpa_division_appointment_request r ON r.id=q.related_ids
              WHERE r.arpa_division_location_id=? AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id)
                AND NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc WHERE dc.issue_row_key=q.row_key AND dc.resolution_status IN('RESOLVED_BY_CORRECTION','KEPT_HISTORICAL_EXCEPTION'))";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$divisionId,self::BASELINE,$divisionId]);return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function reconciliationIssues(string $divisionId):array
    {
        $sql="SELECT CONCAT('LEGACY_RECONCILIATION:',i.id) row_key,i.id reconciliation_item_id,CONCAT('LEGACY_RECONCILIATION_',i.item_type) issue_type,
                     'HISTORICAL_EXCEPTION' severity,o.dad_number officer_number,o.name_with_initials officer_name,
                     COALESCE(i.appointment_type,i.subject_kind) appointment_types,CONCAT(COALESCE(i.effective_from,'Unknown'),' to ',COALESCE(i.effective_to,'Open')) effective_periods,
                     COALESCE(i.effective_from,?) issue_from,COALESCE(i.effective_to,'9999-12-31') issue_to
              FROM legacy_arpa_reconciliation_item i
              JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1
              JOIN officer o ON o.id=i.officer_id
              LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
              WHERE i.active=1 AND i.diagnostic_blocker=1
                AND COALESCE(r.selected_target_arpa_id,i.candidate_arpa_id,p.arpa_location_id)=?
                AND (r.id IS NULL OR r.resolution_status='REQUIRES_FURTHER_REVIEW')";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([self::BASELINE,$divisionId]);return $stmt->fetchAll();
    }

    private function overlaps(string $aStart,string $aEnd,string $bStart,string $bEnd):bool{return $aStart<=$bEnd&&$aEnd>=$bStart;}
    private function lockDivision(string $divisionId):void{$stmt=$this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');$stmt->execute([$divisionId]);if(!$stmt->fetchColumn())throw new DomainException('The selected ARPA Division was not found.');}
    private function displayDate(string $date):string{$time=strtotime($date);return $time===false?$date:date('d M Y',$time);}
}
