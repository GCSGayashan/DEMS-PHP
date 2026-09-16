<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth,ScopeService};
use DomainException;
use PDO;

final class ArpaDivisionTimelineService
{
    public const GAP_STATUSES=['MISSING_BASELINE_PERIOD','HISTORICAL_GAP','CURRENT_WITH_HISTORICAL_GAP'];
    public const STATUS_LABELS=[
        'COMPLETE'=>'Complete',
        'MISSING_BASELINE_PERIOD'=>'Uncovered Baseline Period',
        'HISTORICAL_GAP'=>'Uncovered Period',
        'CURRENT_WITH_HISTORICAL_GAP'=>'Current - Has Uncovered Period',
        'NO_CURRENT_OFFICER'=>'No Current Officer',
        'DATA_ISSUE'=>'Data Issue',
        'MULTIPLE_OPEN_ASSIGNMENTS'=>'Multiple Open Assignments',
        'OVERLAP'=>'Overlap',
        'INVALID_PERIOD'=>'Invalid Period',
    ];

    public function __construct(private readonly PDO $pdo){}

    public static function divisionListSource():string
    {
        $baseline=ArpaDivisionContinuityService::BASELINE;
        $periodSummary=ArpaDivisionContinuityService::summarySource();

        $issueSource=ArpaAppointmentReadService::issueSource();
        $terminal="NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc
                              WHERE dc.issue_row_key=q.row_key
                                AND dc.resolution_status IN('RESOLVED_BY_CORRECTION','KEPT_HISTORICAL_EXCEPTION'))";
        $issueMap="SELECT mapped.division_id,COUNT(DISTINCT mapped.row_key) data_issue_count
                   FROM (
                     SELECT COALESCE(a.arpa_division_location_id,r.arpa_division_location_id) division_id,q.row_key
                     FROM {$issueSource} q
                     LEFT JOIN arpa_division_appointment a ON FIND_IN_SET(a.id,q.related_ids)>0
                     LEFT JOIN arpa_division_appointment_request r ON FIND_IN_SET(r.id,q.related_ids)>0
                       AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment materialized WHERE materialized.request_id=r.id)
                     WHERE COALESCE(a.arpa_division_location_id,r.arpa_division_location_id) IS NOT NULL
                       AND {$terminal}
                     UNION ALL
                     SELECT COALESCE(res.selected_target_arpa_id,i.candidate_arpa_id,p.arpa_location_id),CONCAT('LEGACY_RECONCILIATION:',i.id)
                     FROM legacy_arpa_reconciliation_item i
                     JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1
                     LEFT JOIN legacy_arpa_appointment_resolution res ON res.reconciliation_item_id=i.id
                     WHERE i.active=1 AND i.diagnostic_blocker=1
                       AND COALESCE(res.selected_target_arpa_id,i.candidate_arpa_id,p.arpa_location_id) IS NOT NULL
                       AND (res.id IS NULL OR res.resolution_status='REQUIRES_FURTHER_REVIEW')
                   ) mapped
                   GROUP BY mapped.division_id";

        return "(SELECT arpa.id,arpa.dad_number,arpa.name_en,
                        asc_l.id asc_location_id,asc_l.dad_number asc_dad_number,asc_l.name_en asc_name,
                        district.id district_location_id,district.dad_number district_dad_number,district.name_en district_name,
                        COALESCE(ps.period_count,0) appointment_count,COALESCE(ps.open_count,0) open_count,
                        COALESCE(ps.vacancy_occupancy_count,0) vacancy_occupancy_count,
                        COALESCE(im.data_issue_count,0) data_issue_count,
                        CASE
                          WHEN COALESCE(im.data_issue_count,0)>0 THEN 'DATA_ISSUE'
                          WHEN COALESCE(ps.invalid_count,0)>0 THEN 'INVALID_PERIOD'
                          WHEN COALESCE(ps.open_count,0)>1 THEN 'MULTIPLE_OPEN_ASSIGNMENTS'
                          WHEN COALESCE(ps.overlap_count,0)>0 THEN 'OVERLAP'
                          WHEN COALESCE(ps.period_count,0)=0 OR ps.first_start>'{$baseline}' THEN 'MISSING_BASELINE_PERIOD'
                          WHEN COALESCE(ps.internal_gap_count,0)>0 AND COALESCE(ps.open_count,0)>0 THEN 'CURRENT_WITH_HISTORICAL_GAP'
                          WHEN COALESCE(ps.internal_gap_count,0)>0 THEN 'HISTORICAL_GAP'
                          WHEN COALESCE(ps.vacancy_occupancy_count,0)=0 THEN 'NO_CURRENT_OFFICER'
                          ELSE 'COMPLETE'
                        END timeline_status
                 FROM location arpa
                 JOIN location_type arpa_t ON arpa_t.id=arpa.location_type_id AND arpa_t.system_key='ARPA_DIVISION'
                 JOIN location_relationship asc_rel ON asc_rel.child_location_id=arpa.id
                   AND asc_rel.relationship_type='ASC_ARPA_DIVISION'
                   AND asc_rel.active=1 AND asc_rel.approval_status='APPROVED'
                   AND asc_rel.effective_from<=CURRENT_DATE()
                   AND (asc_rel.effective_to IS NULL OR asc_rel.effective_to>=CURRENT_DATE())
                 JOIN location asc_l ON asc_l.id=asc_rel.parent_location_id
                 LEFT JOIN location_relationship district_rel ON district_rel.child_location_id=asc_l.id
                   AND district_rel.relationship_type='DISTRICT_ASC'
                   AND district_rel.active=1 AND district_rel.approval_status='APPROVED'
                   AND district_rel.effective_from<=CURRENT_DATE()
                   AND (district_rel.effective_to IS NULL OR district_rel.effective_to>=CURRENT_DATE())
                 LEFT JOIN location district ON district.id=district_rel.parent_location_id
                 LEFT JOIN ({$periodSummary}) ps ON ps.division_id=arpa.id
                 LEFT JOIN ({$issueMap}) im ON im.division_id=arpa.id
                 WHERE arpa.approval_status='APPROVED' AND arpa.operational_status='ACTIVE'
                   AND arpa.effective_from<=CURRENT_DATE() AND (arpa.effective_to IS NULL OR arpa.effective_to>=CURRENT_DATE()))";
    }

    /** @return array<string,mixed> */
    public function timeline(string $divisionId,string $viewerId):array
    {
        $division=$this->division($divisionId,$viewerId);
        $continuity=new ArpaDivisionContinuityService($this->pdo);
        $diagnostic=$continuity->requirement($divisionId,ArpaDivisionContinuityService::BASELINE);
        $appointments=$this->appointments($divisionId);
        $issues=$continuity->unresolvedDataIssues($divisionId);

        $overlapKeys=[];
        foreach((array)($diagnostic['overlaps']??[]) as $row)$overlapKeys[(string)$row['source_kind'].':'.(string)$row['source_id']]=true;
        $entries=[];
        foreach($appointments as $appointment){
            $appointment['entry_kind']='APPOINTMENT';
            $appointment['overlap']=isset($overlapKeys[(string)$appointment['source_kind'].':'.(string)$appointment['source_id']]);
            $entries[]=$appointment;
        }
        foreach((array)$diagnostic['gaps'] as $gap)$entries[]=$gap+[
            'entry_kind'=>'MISSING_PERIOD','effective_from'=>$gap['gap_start'],'effective_to'=>$gap['gap_end'],
        ];
        foreach($issues as $issue)$entries[]=$issue+[
            'entry_kind'=>'DATA_ISSUE','effective_from'=>$issue['issue_from']??ArpaDivisionContinuityService::BASELINE,
            'effective_to'=>($issue['issue_to']??null)==='9999-12-31'?null:($issue['issue_to']??null),
            'resolution_status'=>'UNRESOLVED',
        ];
        $rank=['APPOINTMENT'=>0,'DATA_ISSUE'=>1,'MISSING_PERIOD'=>2];
        usort($entries,static fn(array $a,array $b):int=>[
            (string)($a['effective_from']??''),$rank[$a['entry_kind']]??9,(string)($a['source_id']??$a['row_key']??'')
        ]<=>[
            (string)($b['effective_from']??''),$rank[$b['entry_kind']]??9,(string)($b['source_id']??$b['row_key']??'')
        ]);

        $actual=array_values(array_filter($appointments,static fn(array $row):bool=>$row['source_kind']==='OPERATIONAL'));
        $canAddHistorical=Auth::can('arpa.appointment.create')&&ScopeService::canAccessCurrentArpaStage($viewerId,'ASC',(string)$division['asc_location_id']);
        $canCorrect=(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canCorrect($viewerId,(string)$division['asc_location_id']);
        return [
            'division'=>$division,'diagnostic'=>$diagnostic,'entries'=>$entries,'issues'=>$issues,
            'can_add_historical'=>$canAddHistorical,'can_correct'=>$canCorrect,
            'summary'=>[
                'total_appointments'=>count($actual),
                'historical_appointments'=>count(array_filter($actual,static fn(array $row):bool=>$row['effective_to']!==null)),
                'current_open_appointments'=>count(array_filter($actual,static fn(array $row):bool=>$row['effective_to']===null)),
                'missing_periods'=>count((array)$diagnostic['gaps']),
                'data_issues'=>count($issues),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function division(string $divisionId,string $viewerId):array
    {
        if(!ScopeService::canAccessLocation($viewerId,$divisionId))throw new DomainException('This ARPA Division is outside your current working context.');
        $sql="SELECT arpa.id,arpa.dad_number,arpa.name_en,asc_l.id asc_location_id,asc_l.dad_number asc_dad_number,
                     asc_l.name_en asc_name,district.id district_location_id,district.name_en district_name
              FROM location arpa
              JOIN location_type arpa_t ON arpa_t.id=arpa.location_type_id AND arpa_t.system_key='ARPA_DIVISION'
              JOIN location_relationship asc_rel ON asc_rel.child_location_id=arpa.id AND asc_rel.relationship_type='ASC_ARPA_DIVISION'
                AND asc_rel.active=1 AND asc_rel.approval_status='APPROVED' AND asc_rel.effective_from<=CURRENT_DATE()
                AND (asc_rel.effective_to IS NULL OR asc_rel.effective_to>=CURRENT_DATE())
              JOIN location asc_l ON asc_l.id=asc_rel.parent_location_id
              LEFT JOIN location_relationship district_rel ON district_rel.child_location_id=asc_l.id AND district_rel.relationship_type='DISTRICT_ASC'
                AND district_rel.active=1 AND district_rel.approval_status='APPROVED' AND district_rel.effective_from<=CURRENT_DATE()
                AND (district_rel.effective_to IS NULL OR district_rel.effective_to>=CURRENT_DATE())
              LEFT JOIN location district ON district.id=district_rel.parent_location_id
              WHERE arpa.id=? AND arpa.approval_status='APPROVED' AND arpa.operational_status='ACTIVE'";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$divisionId]);$rows=$stmt->fetchAll();
        if(count($rows)!==1)throw new DomainException('The ARPA Division does not have one valid current ASC hierarchy.');
        return $rows[0];
    }

    /** @return array<int,array<string,mixed>> */
    private function appointments(string $divisionId):array
    {
        $statuses="'".implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES)."'";
        $sql="SELECT a.id source_id,a.id appointment_id,a.request_id,a.officer_id,o.dad_number officer_number,
                     o.name_with_initials officer_name,o.nic,a.appointment_type,a.effective_from,c.effective_to,
                     r.workflow_status,a.record_origin,a.legacy_history_only,a.legacy_exception,
                     er.name_en end_reason,'OPERATIONAL' source_kind
              FROM arpa_division_appointment a
              JOIN officer o ON o.id=a.officer_id
              LEFT JOIN arpa_division_appointment_request r ON r.id=a.request_id
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              LEFT JOIN arpa_appointment_end_reason er ON er.id=c.end_reason_id
              WHERE a.arpa_division_location_id=? AND a.effective_from IS NOT NULL
                AND (a.legacy_history_only=0 OR c.id IS NOT NULL)
                AND (c.effective_to IS NULL OR c.effective_to>=?)
              UNION ALL
              SELECT r.id,NULL,r.id,r.officer_id,o.dad_number,o.name_with_initials,o.nic,r.appointment_type,
                     r.requested_effective_from,CASE WHEN r.request_type='TRANSFER' THEN NULL ELSE r.requested_effective_to END,
                     r.workflow_status,r.record_origin,r.legacy_history_only,r.legacy_exception,NULL,'RESERVATION'
              FROM arpa_division_appointment_request r
              JOIN officer o ON o.id=r.officer_id
              WHERE r.arpa_division_location_id=? AND r.record_origin='NATIVE' AND r.legacy_history_only=0
                AND r.request_type IN('APPOINTMENT','TRANSFER') AND r.workflow_status IN({$statuses})
                AND r.requested_effective_from IS NOT NULL
                AND (r.request_type='TRANSFER' OR r.requested_effective_to IS NULL OR r.requested_effective_to>=?)
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id)
              ORDER BY effective_from,effective_to,source_id";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$divisionId,ArpaDivisionContinuityService::BASELINE,$divisionId,ArpaDivisionContinuityService::BASELINE]);return $stmt->fetchAll();
    }
}
