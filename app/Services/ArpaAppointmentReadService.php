<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ScopeService;
use DomainException;
use PDO;

/** Shared operational read rules for ARPA appointment lists, selectors and diagnostics. */
final class ArpaAppointmentReadService
{
    public const CURRENT_ACTION_ISSUES=[
        'DIVISION_MULTIPLE_OPEN','OFFICER_MULTIPLE_PERMANENT','OFFICER_MULTIPLE_ACTING',
        'OFFICER_MULTIPLE_ATTEND_TO_DUTY','DEPENDENT_WITHOUT_PERMANENT',
        'PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY','NON_PERMANENT_SERVICE_WITH_ACTING',
        'EXCLUSIVE_FUNCTION_OVERLAP','MULTIPLE_EXCLUSIVE_FUNCTIONS',
        'MISSING_ASC_OFFICE_ASSIGNMENT','FUTURE_OVERLAP_CONFLICT',
    ];
    public function __construct(private readonly PDO $pdo) {}

    public static function openAppointmentClause(string $appointmentAlias = 'a', string $closureAlias = 'c'): string
    {
        return "{$closureAlias}.id IS NULL";
    }

    public static function vacantDivisionSource(): string
    {
        return "(SELECT arpa.id,arpa.dad_number,arpa.name_en,asc_l.id asc_location_id,asc_l.dad_number asc_dad,asc_l.name_en asc_name,
                    district.id district_location_id,district.name_en district_name,province.id province_location_id,province.name_en province_name,
                    last_a.officer_id last_officer_id,last_o.name_with_initials last_officer,last_a.appointment_type last_appointment_type,
                    last_c.effective_to last_end_date,last_er.name_en last_end_reason,
                    COALESCE(DATE_ADD(last_c.effective_to,INTERVAL 1 DAY),arpa.effective_from) vacancy_since
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
                 LEFT JOIN location_relationship province_rel ON province_rel.child_location_id=district.id AND province_rel.relationship_type='PROVINCE_DISTRICT'
                    AND province_rel.active=1 AND province_rel.approval_status='APPROVED' AND province_rel.effective_from<=CURRENT_DATE()
                    AND (province_rel.effective_to IS NULL OR province_rel.effective_to>=CURRENT_DATE())
                 LEFT JOIN location province ON province.id=province_rel.parent_location_id
                 LEFT JOIN arpa_division_appointment last_a ON last_a.id=(
                    SELECT la.id FROM arpa_division_appointment la
                    LEFT JOIN arpa_division_appointment_closure lc ON lc.appointment_id=la.id
                    WHERE la.arpa_division_location_id=arpa.id AND lc.id IS NOT NULL
                    ORDER BY lc.effective_to DESC,la.effective_from DESC,la.id DESC LIMIT 1)
                 LEFT JOIN arpa_division_appointment_closure last_c ON last_c.appointment_id=last_a.id
                 LEFT JOIN arpa_appointment_end_reason last_er ON last_er.id=last_c.end_reason_id
                 LEFT JOIN officer last_o ON last_o.id=last_a.officer_id
                 WHERE arpa.approval_status='APPROVED' AND arpa.operational_status='ACTIVE'
                   AND arpa.effective_from<=CURRENT_DATE() AND (arpa.effective_to IS NULL OR arpa.effective_to>=CURRENT_DATE())
                   AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment open_a
                     LEFT JOIN arpa_division_appointment_closure open_c ON open_c.appointment_id=open_a.id
                     WHERE open_a.arpa_division_location_id=arpa.id AND open_a.legacy_history_only=0 AND open_c.id IS NULL))";
    }

    /**
     * District-level Open Appointments summary grouped by ASC.
     *
     * @return array{
     *   district_names:array<int,string>,
     *   rows:array<int,array<string,mixed>>,
     *   totals:array{permanent:int,acting:int,duty_covering:int,attend_to_duty:int,total:int}
     * }|null
     */
    public function openAppointmentAscSummary(string $userId): ?array
    {
        $profile=ScopeService::scopeProfile($userId);
        if(($profile['level']??'')!=='DISTRICT')return null;

        $ascs=ScopeService::scopedLocations($userId,'ASC');

        $districtNames=[];
        foreach($profile['scopes']??[] as $scope){
            if(($scope['location_type']??'')==='DISTRICT'){
                $name=trim((string)($scope['name_en']??''));
                if($name!=='')$districtNames[]=$name;
            }
        }
        $districtNames=array_values(array_unique($districtNames));

        $counts=[];
        if($ascs!==[]){
            $ids=array_values(array_map(
                fn(array $row)=>(string)$row['id'],
                $ascs
            ));
            $placeholders=implode(',',array_fill(0,count($ids),'?'));

            $sql="SELECT
                        a.asc_location_id,
                        SUM(a.appointment_type='PERMANENT') permanent_count,
                        SUM(a.appointment_type='ACTING') acting_count,
                        SUM(a.appointment_type='DUTY_COVERING') duty_covering_count,
                        SUM(a.appointment_type='ATTEND_TO_DUTY') attend_to_duty_count,
                        COUNT(*) total_count
                  FROM arpa_division_appointment a
                  LEFT JOIN arpa_division_appointment_closure c
                    ON c.appointment_id=a.id
                  WHERE a.legacy_history_only=0
                    AND ".self::openAppointmentClause('a','c')."
                    AND a.asc_location_id IN ({$placeholders})
                  GROUP BY a.asc_location_id";

            $stmt=$this->pdo->prepare($sql);
            $stmt->execute($ids);

            foreach($stmt->fetchAll() as $row){
                $counts[(string)$row['asc_location_id']]=[
                    'permanent'=>(int)$row['permanent_count'],
                    'acting'=>(int)$row['acting_count'],
                    'duty_covering'=>(int)$row['duty_covering_count'],
                    'attend_to_duty'=>(int)$row['attend_to_duty_count'],
                    'total'=>(int)$row['total_count'],
                ];
            }
        }

        $totals=[
            'permanent'=>0,
            'acting'=>0,
            'duty_covering'=>0,
            'attend_to_duty'=>0,
            'total'=>0,
        ];

        $rows=[];
        foreach($ascs as $asc){
            $rowCounts=$counts[(string)$asc['id']]??[
                'permanent'=>0,
                'acting'=>0,
                'duty_covering'=>0,
                'attend_to_duty'=>0,
                'total'=>0,
            ];

            $rows[]=[
                'asc_id'=>(string)$asc['id'],
                'asc_dad'=>(string)$asc['dad_number'],
                'asc_name'=>(string)$asc['name_en'],
            ]+$rowCounts;

            foreach($totals as $key=>$value){
                $totals[$key]+=$rowCounts[$key];
            }
        }

        return [
            'district_names'=>$districtNames,
            'rows'=>$rows,
            'totals'=>$totals,
        ];
    }
    /** @return array<int,array<string,mixed>> */
    public function eligibleOfficersForAsc(string $userId, string $ascLocationId, string $effectiveDate): array
    {
        if (!ScopeService::canAccessArpaStage($userId, 'ASC', $ascLocationId, $effectiveDate)) return [];
        $sql="SELECT DISTINCT o.id,o.dad_number,o.name_with_initials,o.nic,o.arpa_service_permanency
              FROM officer o
              JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER'
                AND d.active=1 AND d.approval_status='APPROVED'
              JOIN officer_office_assignment oa ON oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED'
                AND oa.effective_from<=? AND (oa.effective_to IS NULL OR oa.effective_to>=?)
              JOIN office ofc ON ofc.id=oa.office_id AND ofc.linked_location_id=?
                AND ofc.approval_status='APPROVED' AND ofc.operational_status='ACTIVE'
                AND ofc.effective_from<=? AND (ofc.effective_to IS NULL OR ofc.effective_to>=?)
              JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE'
              WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE'
                AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?)
              ORDER BY o.name_with_initials,o.dad_number";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$effectiveDate,$effectiveDate,$ascLocationId,$effectiveDate,$effectiveDate,$effectiveDate,$effectiveDate]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function vacantDivisionsForAsc(string $userId, string $ascLocationId, string $effectiveDate): array
    {
        if (!ScopeService::canAccessArpaStage($userId, 'ASC', $ascLocationId, $effectiveDate)) return [];
        $sql="SELECT l.id,l.dad_number,l.name_en FROM location l JOIN location_type t ON t.id=l.location_type_id AND t.system_key='ARPA_DIVISION'
              JOIN location_relationship lr ON lr.child_location_id=l.id AND lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION'
                AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
              WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' AND l.effective_from<=?
                AND (l.effective_to IS NULL OR l.effective_to>=?)
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
                  WHERE a.arpa_division_location_id=l.id AND a.legacy_history_only=0 AND c.id IS NULL)
              ORDER BY l.name_en,l.dad_number";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$ascLocationId,$effectiveDate,$effectiveDate,$effectiveDate,$effectiveDate]);return $stmt->fetchAll();
    }

    public function assertEligibleOfficer(string $officerId, string $ascLocationId, string $effectiveDate): void
    {
        $sql="SELECT COUNT(*) FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER'
              JOIN officer_office_assignment oa ON oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED'
                AND oa.effective_from<=? AND (oa.effective_to IS NULL OR oa.effective_to>=?)
              JOIN office ofc ON ofc.id=oa.office_id AND ofc.linked_location_id=? AND ofc.approval_status='APPROVED' AND ofc.operational_status='ACTIVE'
                AND ofc.effective_from<=? AND (ofc.effective_to IS NULL OR ofc.effective_to>=?)
              JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE'
              WHERE o.id=? AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE'
                AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?)";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$effectiveDate,$effectiveDate,$ascLocationId,$effectiveDate,$effectiveDate,$officerId,$effectiveDate,$effectiveDate]);
        if((int)$stmt->fetchColumn()===0)throw new DomainException('The selected ARPA Officer does not have an approved current assignment to the selected ASC Office.');
    }

    public function assertDivisionVacant(string $ascLocationId, string $divisionId, string $effectiveDate, bool $lock = false): void
    {
        if($lock){$lockStmt=$this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');$lockStmt->execute([$divisionId]);if(!$lockStmt->fetchColumn())throw new DomainException('The selected ARPA Division was not found.');}
        $sql="SELECT COUNT(*) FROM location l JOIN location_type t ON t.id=l.location_type_id AND t.system_key='ARPA_DIVISION'
              JOIN location_relationship lr ON lr.child_location_id=l.id AND lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION'
                AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
              WHERE l.id=? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE'
                AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?)
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
                  WHERE a.arpa_division_location_id=l.id AND a.legacy_history_only=0 AND c.id IS NULL)";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$ascLocationId,$effectiveDate,$effectiveDate,$divisionId,$effectiveDate,$effectiveDate]);
        if((int)$stmt->fetchColumn()===0)throw new DomainException('The selected ARPA Division is outside the ASC, inactive, or already has an open or scheduled appointment.');
    }

    public static function issueSource(string $divisionAppointmentTable = 'arpa_division_appointment'): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $divisionAppointmentTable) !== 1) {
            throw new DomainException('Invalid appointment diagnostic source.');
        }
        $legacy="CASE WHEN MIN(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1)=1 THEN 'HISTORICAL_EXCEPTION' ELSE 'ERROR' END";
        $locationValidationDate=ArpaAppointmentLocationPolicy::validationDateSql('a.effective_from');
        $arpaAscRelationship=ArpaAppointmentLocationPolicy::relationshipAtSql('lr',$locationValidationDate);
        $group="GROUP_CONCAT(DISTINCT a.arpa_name_snapshot ORDER BY a.arpa_name_snapshot SEPARATOR ', ') arpa_divisions,GROUP_CONCAT(DISTINCT a.appointment_type ORDER BY a.appointment_type SEPARATOR ', ') appointment_types,GROUP_CONCAT(DISTINCT CONCAT(a.effective_from,' to ',COALESCE(c.effective_to,'Open')) SEPARATOR '; ') effective_periods,GROUP_CONCAT(DISTINCT a.id SEPARATOR ',') related_ids";
        $base="a.officer_id,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,MIN(a.asc_location_id) asc_location_id,MIN(a.asc_name_snapshot) asc_name,{$group},IF(MIN(a.record_origin='LEGACY_IMPORT')=1,'LEGACY_IMPORT','NATIVE') origin";
        $unions=[];
        $unions[]="SELECT CONCAT('DIVISION_MULTIPLE_OPEN:',a.arpa_division_location_id) row_key,'DIVISION_MULTIPLE_OPEN' issue_type,{$legacy} severity,{$base},'One ARPA Division has more than one open appointment.' explanation,'Review the competing evidence and apply a direct, audited data correction where appropriate.' recommended_action FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 GROUP BY a.arpa_division_location_id HAVING COUNT(*)>1";
        foreach(['PERMANENT','ACTING','ATTEND_TO_DUTY'] as $type){$key=$type==='ATTEND_TO_DUTY'?'OFFICER_MULTIPLE_ATTEND_TO_DUTY':'OFFICER_MULTIPLE_'.$type;$unions[]="SELECT CONCAT('{$key}:',a.officer_id) row_key,'{$key}' issue_type,{$legacy} severity,{$base},'Officer exceeds the allowed number of open {$type} appointments.' explanation,'Review the competing evidence and apply a direct, audited data correction where appropriate.' recommended_action FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 AND a.appointment_type='{$type}' GROUP BY a.officer_id HAVING COUNT(*)>1";}
        $unions[]="SELECT CONCAT('DEPENDENT_WITHOUT_PERMANENT:',a.id),'DEPENDENT_WITHOUT_PERMANENT',IF(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to Open'),a.id,a.record_origin,'Dependent appointment has no qualifying Permanent appointment.','Review all Permanent evidence; never manufacture a foundation appointment.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 AND a.appointment_type<>'PERMANENT' AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment p LEFT JOIN arpa_division_appointment_closure pc ON pc.appointment_id=p.id WHERE p.officer_id=a.officer_id AND p.appointment_type='PERMANENT' AND p.legacy_history_only=0 AND p.effective_from<=a.effective_from AND (pc.effective_to IS NULL OR pc.effective_to>=a.effective_from))";
        $unions[]="SELECT CONCAT('PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY:',a.id),'PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY',IF(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to Open'),a.id,a.record_origin,'Permanent-in-service Officer has an open Attend to the Duty appointment.','Review the appointment evidence and apply a direct data correction only when the stored representation is wrong.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 AND a.appointment_type='ATTEND_TO_DUTY' AND o.arpa_service_permanency='PERMANENT_IN_SERVICE'";
        $unions[]="SELECT CONCAT('NON_PERMANENT_SERVICE_WITH_ACTING:',a.id),'NON_PERMANENT_SERVICE_WITH_ACTING',IF(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to Open'),a.id,a.record_origin,'Not-permanent-in-service Officer has an open Acting appointment.','Review the appointment evidence and apply a direct data correction only when the stored representation is wrong.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 AND a.appointment_type='ACTING' AND o.arpa_service_permanency='NOT_PERMANENT_IN_SERVICE'";
        $unions[]="SELECT CONCAT('EXCLUSIVE_FUNCTION_OVERLAP:',s.id),'EXCLUSIVE_FUNCTION_OVERLAP',IF(s.record_origin='LEGACY_IMPORT' OR s.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),s.officer_id,o.dad_number,o.name_with_initials,o.nic,s.asc_location_id,s.asc_name_snapshot,s.subject_name_snapshot,s.subject_kind_snapshot,CONCAT(s.effective_from,' to Open'),s.id,s.record_origin,'Officer has an exclusive function together with another open Division or function assignment.','End the conflicting assignment through its normal workflow.' FROM arpa_subject_assignment s JOIN officer o ON o.id=s.officer_id LEFT JOIN arpa_subject_assignment_closure sc ON sc.assignment_id=s.id WHERE sc.id IS NULL AND s.officer_exclusive_snapshot=1 AND (EXISTS(SELECT 1 FROM arpa_division_appointment da LEFT JOIN arpa_division_appointment_closure dc ON dc.appointment_id=da.id WHERE da.officer_id=s.officer_id AND dc.id IS NULL) OR EXISTS(SELECT 1 FROM arpa_subject_assignment other_s LEFT JOIN arpa_subject_assignment_closure other_c ON other_c.assignment_id=other_s.id WHERE other_s.officer_id=s.officer_id AND other_s.id<>s.id AND other_c.id IS NULL))";
        $unions[]="SELECT CONCAT('MULTIPLE_EXCLUSIVE_FUNCTIONS:',s.officer_id),'MULTIPLE_EXCLUSIVE_FUNCTIONS',CASE WHEN MIN(s.record_origin='LEGACY_IMPORT' OR s.legacy_exception=1)=1 THEN 'HISTORICAL_EXCEPTION' ELSE 'ERROR' END,s.officer_id,o.dad_number,o.name_with_initials,o.nic,MIN(s.asc_location_id),MIN(s.asc_name_snapshot),GROUP_CONCAT(DISTINCT s.subject_name_snapshot SEPARATOR ', '),GROUP_CONCAT(DISTINCT s.subject_kind_snapshot SEPARATOR ', '),GROUP_CONCAT(DISTINCT CONCAT(s.effective_from,' to Open') SEPARATOR '; '),GROUP_CONCAT(DISTINCT s.id),IF(MIN(s.record_origin='LEGACY_IMPORT')=1,'LEGACY_IMPORT','NATIVE'),'Officer has more than one simultaneous exclusive function.','Review competing functions and end invalid assignments through workflow.' FROM arpa_subject_assignment s JOIN officer o ON o.id=s.officer_id LEFT JOIN arpa_subject_assignment_closure sc ON sc.assignment_id=s.id WHERE sc.id IS NULL AND s.officer_exclusive_snapshot=1 GROUP BY s.officer_id HAVING COUNT(*)>1";
        $unions[]="SELECT CONCAT('MISSING_ASC_OFFICE_ASSIGNMENT:D:',a.id),'MISSING_ASC_OFFICE_ASSIGNMENT','ERROR',a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to Open'),a.id,a.record_origin,'Officer has no approved current assignment to the matching ASC Office.','Open Officer profile and use the normal Office-assignment workflow.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 AND NOT EXISTS(SELECT 1 FROM officer_office_assignment oa JOIN office ofc ON ofc.id=oa.office_id JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE' WHERE oa.officer_id=a.officer_id AND ofc.linked_location_id=a.asc_location_id AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE()))";
        $unions[]="SELECT CONCAT('MISSING_ASC_OFFICE_ASSIGNMENT:S:',s.id),'MISSING_ASC_OFFICE_ASSIGNMENT','ERROR',s.officer_id,o.dad_number,o.name_with_initials,o.nic,s.asc_location_id,s.asc_name_snapshot,s.subject_name_snapshot,s.subject_kind_snapshot,CONCAT(s.effective_from,' to Open'),s.id,s.record_origin,'Officer has no approved current assignment to the matching ASC Office.','Open Officer profile and use the normal Office-assignment workflow.' FROM arpa_subject_assignment s JOIN officer o ON o.id=s.officer_id LEFT JOIN arpa_subject_assignment_closure sc ON sc.assignment_id=s.id WHERE sc.id IS NULL AND NOT EXISTS(SELECT 1 FROM officer_office_assignment oa JOIN office ofc ON ofc.id=oa.office_id JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE' WHERE oa.officer_id=s.officer_id AND ofc.linked_location_id=s.asc_location_id AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE()))";
        $unions[]="SELECT CONCAT('APPOINTMENT_OUTSIDE_ASC:',a.id),'APPOINTMENT_OUTSIDE_ASC',IF(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to ',COALESCE(c.effective_to,'Open')),a.id,a.record_origin,'This ARPA Division is not listed under the selected Agrarian Service Center.','Check the ASC and ARPA Division.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE NOT EXISTS(SELECT 1 FROM location_relationship lr WHERE lr.parent_location_id=a.asc_location_id AND lr.child_location_id=a.arpa_division_location_id AND lr.relationship_type='ASC_ARPA_DIVISION' AND {$arpaAscRelationship})";
        $unions[]="SELECT CONCAT('INVALID_DATE_RANGE:',a.id),'INVALID_DATE_RANGE',IF(a.record_origin='LEGACY_IMPORT' OR a.legacy_exception=1,'HISTORICAL_EXCEPTION','ERROR'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to ',c.effective_to),a.id,a.record_origin,'Appointment end date precedes its effective start date.','Review the immutable source and create a controlled correction where permitted.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.effective_to<a.effective_from";
        $unions[]="SELECT CONCAT('ENDED_APPOINTMENT_WITHOUT_END_REASON:',a.id),'ENDED_APPOINTMENT_WITHOUT_END_REASON',IF(a.record_origin='LEGACY_IMPORT','HISTORICAL_EXCEPTION','WARNING'),a.officer_id,o.dad_number,o.name_with_initials,o.nic,a.asc_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.appointment_type,CONCAT(a.effective_from,' to ',c.effective_to),a.id,a.record_origin,'Ended appointment has no normalized or legacy end reason.','Review source evidence; preserve unavailable legacy reasons truthfully.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.end_reason_id IS NULL AND NULLIF(TRIM(c.legacy_reason_text),'') IS NULL";
        $unions[]="SELECT CONCAT('OPEN_APPOINTMENT_WITH_END_REASON:',r.id),'OPEN_APPOINTMENT_WITH_END_REASON',IF(r.record_origin='LEGACY_IMPORT' OR r.legacy_exception=1,'HISTORICAL_EXCEPTION','WARNING'),r.officer_id,o.dad_number,o.name_with_initials,o.nic,r.asc_location_id,asc_l.name_en,arpa.name_en,r.appointment_type,CONCAT(COALESCE(r.requested_effective_from,'Unknown'),' to Open'),r.id,r.record_origin,'Request has an end reason but no effective end date.','Return the request for correction before approval.' FROM arpa_division_appointment_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id WHERE r.requested_effective_to IS NULL AND r.end_reason_id IS NOT NULL";
        $unions[]="SELECT CONCAT('FUTURE_OVERLAP_CONFLICT:',a.arpa_division_location_id),'FUTURE_OVERLAP_CONFLICT',{$legacy},{$base},'A scheduled appointment overlaps another open appointment for this ARPA Division.','Use the normal workflow for a genuine future business event; use direct correction only for incorrect stored data.' FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL AND a.legacy_history_only=0 GROUP BY a.arpa_division_location_id HAVING COUNT(*)>1 AND MAX(a.effective_from>CURRENT_DATE())=1";
        $unions[]="SELECT CONCAT('LEGACY_HISTORICAL_EXCEPTION:',r.id),'LEGACY_HISTORICAL_EXCEPTION','HISTORICAL_EXCEPTION',r.officer_id,o.dad_number,o.name_with_initials,o.nic,r.asc_location_id,asc_l.name_en,arpa.name_en,r.appointment_type,CONCAT(COALESCE(r.requested_effective_from,'Unknown'),' to ',COALESCE(r.requested_effective_to,'Open')),r.id,r.record_origin,CONCAT('Legacy record is preserved as a non-operational exception: ',COALESCE(JSON_UNQUOTE(r.legacy_exception_codes_json),'[]')),'Review immutable source provenance; do not activate or rewrite the historical record.' FROM arpa_division_appointment_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id WHERE r.record_origin='LEGACY_IMPORT' AND r.legacy_exception=1 AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id)";
        $unions[]="SELECT CONCAT('MANUAL_REVIEW_REQUIRED:',p.reconciled_business_key),'MANUAL_REVIEW_REQUIRED','HISTORICAL_EXCEPTION',p.officer_id,o.dad_number,o.name_with_initials,o.nic,p.asc_location_id,asc_l.name_en,arpa.name_en,COALESCE(p.appointment_type,p.subject_kind),CONCAT(COALESCE(p.effective_from,'Unknown'),' to ',COALESCE(p.effective_to,'Open')),p.reconciled_business_key,'LEGACY_REVIEW',CONCAT('Legacy record was skipped for manual review: ',COALESCE(JSON_UNQUOTE(p.blocker_types_json),'[]')),'Resolve only through Legacy Migration Review; never guess identity, location, or dates.' FROM legacy_arpa_appointment_preview p JOIN officer o ON o.id=p.officer_id LEFT JOIN location asc_l ON asc_l.id=p.asc_location_id LEFT JOIN location arpa ON arpa.id=p.arpa_location_id JOIN (SELECT DISTINCT reconciled_business_key FROM legacy_arpa_appointment_migration_issue WHERE issue_type='MANUAL_REVIEW_SKIPPED') skipped ON skipped.reconciled_business_key=p.reconciled_business_key WHERE NOT EXISTS(SELECT 1 FROM legacy_arpa_appointment_business_record br WHERE br.reconciled_business_key=p.reconciled_business_key)";
        $issues=implode(' UNION ALL ',$unions);
        if ($divisionAppointmentTable !== 'arpa_division_appointment') {
            $issues=str_replace('arpa_division_appointment ', $divisionAppointmentTable.' ', $issues);
        }
        return "(SELECT issue_rows.row_key,issue_rows.issue_type,
                    CASE WHEN issue_rows.origin LIKE 'LEGACY%' THEN 'HISTORICAL_EXCEPTION' ELSE issue_rows.severity END severity,
                    issue_rows.officer_id,issue_rows.officer_number,issue_rows.officer_name,issue_rows.nic,
                    issue_rows.asc_location_id,issue_rows.asc_name,issue_rows.arpa_divisions,issue_rows.appointment_types,
                    issue_rows.effective_periods,issue_rows.related_ids,issue_rows.origin,issue_rows.explanation,
                    issue_rows.recommended_action
                 FROM ({$issues}) issue_rows)";
    }

    public static function currentActionIssuePredicate(string $alias='q'):string
    {
        if(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/',$alias)!==1)throw new DomainException('Invalid diagnostic alias.');
        return $alias.".severity='ERROR'";
    }
}
