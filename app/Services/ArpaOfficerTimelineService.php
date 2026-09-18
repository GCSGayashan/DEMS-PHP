<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ScopeService;
use DomainException;
use PDO;

final class ArpaOfficerTimelineService
{
    public const STATUS_LABELS=['COMPLETE'=>'Complete','DATA_ISSUE'=>'Data Issue'];

    public function __construct(private readonly PDO $pdo){}

    public static function periodSource(bool $includeOpenHistorical=false):string
    {
        $statuses="'".implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES)."'";
        $historyFilter=$includeOpenHistorical?'':' AND (a.legacy_history_only=0 OR c.id IS NOT NULL)';
        return "(SELECT a.id source_id,a.id appointment_id,a.request_id,a.officer_id,a.appointment_type,
                        a.asc_location_id,a.arpa_division_location_id,a.asc_dad_snapshot asc_dad,
                        a.asc_name_snapshot asc_name,a.district_dad_snapshot district_dad,
                        a.district_name_snapshot district_name,a.arpa_dad_snapshot arpa_dad,
                        a.arpa_name_snapshot arpa_name,a.effective_from,c.effective_to,
                        r.workflow_status,a.record_origin,a.legacy_history_only,a.legacy_exception,
                        a.legacy_exception_codes_json,c.id closure_id,
                        'OPERATIONAL' source_kind
                 FROM arpa_division_appointment a
                 JOIN arpa_division_appointment_request r ON r.id=a.request_id
                 LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
                 WHERE a.effective_from IS NOT NULL{$historyFilter}
                 UNION ALL
                 SELECT r.id,NULL,r.id,r.officer_id,r.appointment_type,r.asc_location_id,
                        r.arpa_division_location_id,asc_l.dad_number,asc_l.name_en,
                        district.dad_number,district.name_en,arpa.dad_number,arpa.name_en,
                        r.requested_effective_from,
                        CASE WHEN r.request_type='TRANSFER' THEN NULL ELSE r.requested_effective_to END,
                        r.workflow_status,r.record_origin,r.legacy_history_only,r.legacy_exception,
                        r.legacy_exception_codes_json,NULL,
                        'RESERVATION'
                 FROM arpa_division_appointment_request r
                 LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id
                 LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id
                 LEFT JOIN location_relationship district_rel ON district_rel.child_location_id=r.asc_location_id
                   AND district_rel.relationship_type='DISTRICT_ASC' AND district_rel.active=1
                   AND district_rel.approval_status='APPROVED' AND district_rel.effective_from<=CURRENT_DATE()
                   AND (district_rel.effective_to IS NULL OR district_rel.effective_to>=CURRENT_DATE())
                 LEFT JOIN location district ON district.id=district_rel.parent_location_id
                 WHERE r.deleted_at IS NULL AND r.record_origin='NATIVE' AND r.legacy_history_only=0
                   AND r.request_type IN('APPOINTMENT','TRANSFER') AND r.workflow_status IN({$statuses})
                   AND r.requested_effective_from IS NOT NULL
                   AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id))";
    }

    public static function officerListSource(bool $restricted):string
    {
        $periods=self::periodSource();
        $issueSource=ArpaAppointmentReadService::issueSource();
        $periodScope=$restricted?'JOIN visible_locations period_scope ON period_scope.id=p.asc_location_id':'';
        $issueScope=$restricted?'JOIN visible_locations issue_scope ON issue_scope.id=q.asc_location_id':'';
        $terminal="NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc
                              WHERE dc.issue_row_key=q.row_key
                                AND dc.resolution_status IN('RESOLVED_BY_CORRECTION','KEPT_HISTORICAL_EXCEPTION'))";
        return "(SELECT o.id,o.dad_number,o.name_with_initials,o.nic,o.arpa_service_permanency,
                        GROUP_CONCAT(DISTINCT CASE WHEN p.appointment_type='PERMANENT'
                          THEN CONCAT(p.arpa_name,' (',p.effective_from,' - ',COALESCE(p.effective_to,'Open'),')') END
                          ORDER BY p.effective_from SEPARATOR '; ') permanent_appointments,
                        SUM(p.appointment_type<>'PERMANENT') additional_appointment_count,
                        GROUP_CONCAT(DISTINCT CASE WHEN p.appointment_type<>'PERMANENT'
                          THEN p.appointment_type END ORDER BY p.appointment_type SEPARATOR ', ') additional_appointment_types,
                        COALESCE(MAX(i.data_issue_count),0) data_issue_count,
                        CASE WHEN COALESCE(MAX(i.data_issue_count),0)>0 THEN 'DATA_ISSUE' ELSE 'COMPLETE' END timeline_status
                 FROM {$periods} p
                 {$periodScope}
                 JOIN officer o ON o.id=p.officer_id
                 LEFT JOIN (
                   SELECT q.officer_id,COUNT(DISTINCT q.row_key) data_issue_count
                   FROM {$issueSource} q {$issueScope}
                   WHERE q.officer_id IS NOT NULL AND {$terminal}
                   GROUP BY q.officer_id
                 ) i ON i.officer_id=o.id
                 GROUP BY o.id,o.dad_number,o.name_with_initials,o.nic,o.arpa_service_permanency)";
    }

    /** @return array<string,mixed> */
    public function timeline(string $officerId,string $viewerId):array
    {
        $appointments=$this->appointments($officerId,$viewerId);
        if($appointments===[])throw new DomainException('This Officer has no ARPA appointments in your current working context.');
        $officer=$this->officer($officerId);
        $issues=$this->existingIssues($officerId,$viewerId);
        $known=[];$knownTypes=[];foreach($issues as $issue){$known[(string)$issue['row_key']]=true;$knownTypes[(string)$issue['issue_type']]=true;}
        $conflictRows=array_values(array_filter($appointments,static fn(array $row):bool=>(int)$row['legacy_history_only']===0||!empty($row['closure_id'])));
        foreach($this->derivedConflicts($conflictRows,(string)($officer['arpa_service_permanency']??'')) as $issue){
            if(!isset($known[(string)$issue['row_key']])&&!isset($knownTypes[(string)$issue['issue_type']]))$issues[]=$issue;
        }
        usort($issues,static fn(array $a,array $b):int=>[(string)($a['issue_from']??''),(string)$a['row_key']]<=>[(string)($b['issue_from']??''),(string)$b['row_key']]);
        foreach($appointments as &$appointment){
            $appointment['issue_keys']=[];
            foreach($issues as $issue)if(in_array((string)$appointment['source_id'],(array)($issue['affected_source_ids']??[]),true))$appointment['issue_keys'][]=$issue['row_key'];
        }unset($appointment);
        $counts=array_fill_keys(ArpaAppointmentRules::APPOINTMENT_TYPES,0);
        foreach($appointments as $appointment)$counts[(string)$appointment['appointment_type']]++;
        $canCorrect=(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canCorrect($viewerId,(string)$appointments[0]['asc_location_id']);
        return ['officer'=>$officer,'appointments'=>$appointments,'issues'=>$issues,'can_correct'=>$canCorrect,'summary'=>[
            'total_appointments'=>count($appointments),'permanent'=>$counts['PERMANENT'],'acting'=>$counts['ACTING'],
            'attend_to_duty'=>$counts['ATTEND_TO_DUTY'],'duty_covering'=>$counts['DUTY_COVERING'],
            'current_open'=>count(array_filter($appointments,static fn(array $r):bool=>$r['display_status']==='Current / Open')),'data_issues'=>count($issues),
        ]];
    }

    /** @return array<string,mixed> */
    private function officer(string $id):array
    {
        $stmt=$this->pdo->prepare("SELECT o.id,o.dad_number,o.name_with_initials,o.nic,o.arpa_service_permanency,
                                          ofc.dad_number primary_office_dad,ofc.name_en primary_office_name
                                   FROM officer o LEFT JOIN office ofc ON ofc.id=o.primary_office_id WHERE o.id=?");
        $stmt->execute([$id]);$row=$stmt->fetch();if(!$row)throw new DomainException('Officer was not found.');return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function appointments(string $officerId,string $viewerId):array
    {
        $restricted=ScopeService::requiresGeographicRestriction($viewerId);
        $with=$restricted?ScopeService::visibleLocationsCte($viewerId):'';
        $scope=$restricted?'JOIN visible_locations vl ON vl.id=p.asc_location_id':'';
        $sql=($with!==''?$with.' ':'')."SELECT p.* FROM ".self::periodSource(true)." p {$scope} WHERE p.officer_id=? ORDER BY p.effective_from,p.effective_to,p.source_id";
        $params=$restricted?ScopeService::visibleLocationParams($viewerId):[];$params[]=$officerId;
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();foreach($rows as &$row)$row=array_merge($row,ArpaAppointmentDisplayPresentation::decorate($row));unset($row);return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function existingIssues(string $officerId,string $viewerId):array
    {
        $restricted=ScopeService::requiresGeographicRestriction($viewerId);$with=$restricted?ScopeService::visibleLocationsCte($viewerId):'';
        $scope=$restricted?'JOIN visible_locations vl ON vl.id=q.asc_location_id':'';
        $terminal="NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc WHERE dc.issue_row_key=q.row_key AND dc.resolution_status IN('RESOLVED_BY_CORRECTION','KEPT_HISTORICAL_EXCEPTION'))";
        $sql=($with!==''?$with.' ':'')."SELECT q.* FROM ".ArpaAppointmentReadService::issueSource()." q {$scope} WHERE q.officer_id=? AND {$terminal} ORDER BY q.row_key";
        $params=$restricted?ScopeService::visibleLocationParams($viewerId):[];$params[]=$officerId;
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
        foreach($rows as &$row){$row['existing_data_issue']=true;$row['affected_source_ids']=array_values(array_filter(array_map('trim',explode(',',(string)($row['related_ids']??'')))));$row['issue_from']=null;$row['issue_to']=null;}unset($row);
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public function derivedConflicts(array $rows,string $permanency):array
    {
        $issues=[];$count=count($rows);
        for($i=0;$i<$count;$i++)for($j=$i+1;$j<$count;$j++){
            $a=$rows[$i];$b=$rows[$j];if($a['appointment_type']!==$b['appointment_type'])continue;
            if(!ArpaAppointmentRules::intervalsOverlap((string)$a['effective_from'],$a['effective_to'],(string)$b['effective_from'],$b['effective_to']))continue;
            $type=(string)$a['appointment_type'];$code=null;
            if(in_array($type,['PERMANENT','ACTING','ATTEND_TO_DUTY'],true))$code=$type==='ATTEND_TO_DUTY'?'OFFICER_MULTIPLE_ATTEND_TO_DUTY':'OFFICER_MULTIPLE_'.$type;
            elseif($type==='DUTY_COVERING'&&(string)$a['arpa_division_location_id']===(string)$b['arpa_division_location_id'])$code='OFFICER_DUPLICATE_DUTY_COVERING';
            if($code!==null)$issues[]=$this->derivedIssue($code,[$a,$b],max((string)$a['effective_from'],(string)$b['effective_from']),$this->minimumEnd($a['effective_to'],$b['effective_to']));
        }
        foreach($rows as $row){
            $type=(string)$row['appointment_type'];if(!in_array($type,['ACTING','ATTEND_TO_DUTY','DUTY_COVERING'],true))continue;
            $hasPermanent=false;foreach($rows as $permanent)if($permanent['source_kind']==='OPERATIONAL'&&$permanent['appointment_type']==='PERMANENT'&&(string)$permanent['effective_from']<=(string)$row['effective_from']&&($permanent['effective_to']===null||(string)$permanent['effective_to']>=(string)$row['effective_from'])){$hasPermanent=true;break;}
            if(!$hasPermanent)$issues[]=$this->derivedIssue('DEPENDENT_WITHOUT_QUALIFYING_PERMANENT',[$row],(string)$row['effective_from'],$row['effective_to']);
            if($type==='ACTING'&&$permanency==='NOT_PERMANENT_IN_SERVICE')$issues[]=$this->derivedIssue('NON_PERMANENT_SERVICE_WITH_ACTING',[$row],(string)$row['effective_from'],$row['effective_to']);
            if($type==='ATTEND_TO_DUTY'&&$permanency==='PERMANENT_IN_SERVICE')$issues[]=$this->derivedIssue('PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY',[$row],(string)$row['effective_from'],$row['effective_to']);
        }
        $unique=[];foreach($issues as $issue)$unique[(string)$issue['row_key']]=$issue;return array_values($unique);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function derivedIssue(string $code,array $rows,string $from,?string $to):array
    {
        $ids=array_map(static fn(array $r):string=>(string)$r['source_id'],$rows);sort($ids);
        return ['row_key'=>'OFFICER_TIMELINE:'.$code.':'.implode(':',$ids),'issue_type'=>$code,'severity'=>'ERROR',
            'explanation'=>'The Officer appointment timeline conflicts with the established ARPA appointment rules.',
            'recommended_action'=>'Review the affected canonical appointments and use the existing Appointment Data Issue workflow where available.',
            'issue_from'=>$from,'issue_to'=>$to,'affected_source_ids'=>$ids,'related_ids'=>implode(',',$ids),'existing_data_issue'=>false];
    }

    private function minimumEnd(?string $a,?string $b):?string
    {
        if($a===null)return $b;if($b===null)return $a;return min($a,$b);
    }
}
