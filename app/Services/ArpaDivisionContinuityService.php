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
                AND a.effective_from<=? AND (c.effective_to IS NULL OR c.effective_to>=?)
              UNION ALL
              SELECT r.arpa_division_location_id,r.id,r.requested_effective_from,
                     CASE WHEN r.request_type='TRANSFER' THEN NULL ELSE r.requested_effective_to END,'RESERVATION'
              FROM arpa_division_appointment_request r
              WHERE r.arpa_division_location_id IN({$marks}) AND r.id<>COALESCE(?, '')
                AND r.record_origin='NATIVE' AND r.legacy_history_only=0
                AND r.request_type IN('APPOINTMENT','TRANSFER')
                AND r.workflow_status IN({$statuses})
                AND r.requested_effective_from IS NOT NULL AND r.requested_effective_from<=?";
        $params=array_merge(
            $divisionIds,[$excludeAppointmentId,$proposedStart,self::BASELINE],
            $divisionIds,[$excludeRequestId],ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES,[$proposedStart]
        );
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$byDivision=[];
        foreach($stmt->fetchAll() as $row)$byDivision[(string)$row['division_id']][]=$row;

        $result=[];
        foreach($divisionIds as $divisionId)$result[$divisionId]=$this->calculate($byDivision[$divisionId]??[],$proposedStart);
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
        if($lock){
            $stmt=$this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');$stmt->execute([$divisionId]);
            if(!$stmt->fetchColumn())throw new DomainException('The selected ARPA Division was not found.');
        }
        $requirement=$this->requirement($divisionId,$proposedStart,$excludeRequestId,$excludeAppointmentId);
        if($checkDataIssues){
            $issue=$this->blockingDataIssue($divisionId,$requirement,$proposedStart);
            if($issue!==null){
                throw new DomainException('This ARPA Division has an unresolved Appointment Data Issue for the required period. Resolve the Data Issue before adding a new assignment.');
            }
        }
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

    /** @return array<string,mixed>|null */
    public function blockingDataIssue(string $divisionId,array $requirement,string $proposedStart):?array
    {
        $windows=[[$proposedStart,'9999-12-31']];
        if($requirement['relation']==='GAP')$windows[]=[(string)$requirement['required_next_start'],(string)$requirement['gap_end']];
        if($requirement['last_authoritative_start']!==null)$windows[]=[(string)$requirement['last_authoritative_start'],(string)($requirement['last_authoritative_end']??'9999-12-31')];

        foreach($this->canonicalIssues($divisionId) as $issue){
            foreach($windows as [$from,$to])if($this->overlaps((string)$issue['issue_from'],(string)$issue['issue_to'],$from,$to))return $issue;
        }
        foreach($this->reconciliationIssues($divisionId) as $issue){
            foreach($windows as [$from,$to])if($this->overlaps((string)$issue['issue_from'],(string)$issue['issue_to'],$from,$to))return $issue;
        }
        return null;
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
            $rows[]=$request+[
                'last_covered_through'=>$requirement['last_covered_through'],
                'required_next_start'=>$requirement['required_next_start'],
                'gap_start'=>$requirement['required_next_start'],
                'gap_end'=>$requirement['gap_end'],
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
        $cursor=self::BASELINE;
        $lastStart=null;$lastEnd=null;$used=0;
        foreach($periods as $period){
            $start=max(self::BASELINE,(string)$period['effective_from']);$end=$period['effective_to']===null?'9999-12-31':(string)$period['effective_to'];
            if($end<self::BASELINE)continue;
            if($start>$cursor)break;
            $used++;$lastStart=(string)$period['effective_from'];$lastEnd=$period['effective_to']===null?null:(string)$period['effective_to'];
            if($end==='9999-12-31'){$cursor='9999-12-31';break;}
            $next=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
            if($next>$cursor)$cursor=$next;
        }
        $relation=$proposedStart===$cursor?'EXACT':($proposedStart>$cursor?'GAP':'OVERLAP');
        $lastCovered=$cursor===self::BASELINE||$cursor==='9999-12-31'?null:(new DateTimeImmutable($cursor))->modify('-1 day')->format('Y-m-d');
        $gapEnd=$relation==='GAP'?(new DateTimeImmutable($proposedStart))->modify('-1 day')->format('Y-m-d'):null;
        return [
            'required_next_start'=>$cursor,
            'last_covered_through'=>$lastCovered,
            'last_authoritative_start'=>$lastStart,
            'last_authoritative_end'=>$lastEnd,
            'authoritative_period_count'=>$used,
            'relation'=>$relation,
            'gap_start'=>$relation==='GAP'?$cursor:null,
            'gap_end'=>$gapEnd,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function canonicalIssues(string $divisionId):array
    {
        $source=ArpaAppointmentReadService::issueSource();
        $sql="SELECT q.row_key,q.issue_type,MIN(a.effective_from) issue_from,
                     MAX(COALESCE(c.effective_to,'9999-12-31')) issue_to
              FROM {$source} q
              JOIN arpa_division_appointment a ON FIND_IN_SET(a.id,q.related_ids)>0
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              WHERE a.arpa_division_location_id=?
                AND NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc
                               WHERE dc.issue_row_key=q.row_key AND dc.resolution_status='KEPT_HISTORICAL_EXCEPTION')
              GROUP BY q.row_key,q.issue_type";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$divisionId]);return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function reconciliationIssues(string $divisionId):array
    {
        $sql="SELECT i.id reconciliation_item_id,CONCAT('LEGACY_RECONCILIATION_',i.item_type) issue_type,
                     COALESCE(i.effective_from,?) issue_from,COALESCE(i.effective_to,'9999-12-31') issue_to
              FROM legacy_arpa_reconciliation_item i
              JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1
              LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
              WHERE i.active=1 AND i.diagnostic_blocker=1
                AND COALESCE(r.selected_target_arpa_id,i.candidate_arpa_id,p.arpa_location_id)=?
                AND (r.id IS NULL OR r.resolution_status='REQUIRES_FURTHER_REVIEW')";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([self::BASELINE,$divisionId]);return $stmt->fetchAll();
    }

    private function overlaps(string $aStart,string $aEnd,string $bStart,string $bEnd):bool{return $aStart<=$bEnd&&$aEnd>=$bStart;}
    private function displayDate(string $date):string{$time=strtotime($date);return $time===false?$date:date('d M Y',$time);}
}
