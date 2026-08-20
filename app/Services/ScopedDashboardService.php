<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\ScopeService;
use PDO;

final class ScopedDashboardService
{
    public function __construct(private readonly PDO $pdo){}

    public function dashboard(string $userId,bool $includeLegacy=false):array
    {
        $profile=ScopeService::scopeProfile($userId);
        if($profile['enterprise'])return ['mode'=>'ENTERPRISE','profile'=>$profile,'counts'=>$this->enterpriseCounts(),'charts'=>[],'legacy'=>null];
        $with=ScopeService::visibleLocationsCte($userId);$params=ScopeService::visibleLocationParams($userId);$officeLocationJoin=$profile['level']==='ASC'?'JOIN scope_seeds ov ON ov.id=ofc.linked_location_id JOIN office_type oot ON oot.id=ofc.office_type_id AND oot.system_key=\'ASC_OFFICE\'':'JOIN visible_locations ov ON ov.id=ofc.linked_location_id';
        $metricsSql=$with." SELECT
          (SELECT COUNT(*) FROM location l JOIN location_type t ON t.id=l.location_type_id JOIN visible_locations vl ON vl.id=l.id WHERE t.system_key='ASC' AND l.approval_status='APPROVED') asc_count,
          (SELECT COUNT(*) FROM location l JOIN location_type t ON t.id=l.location_type_id JOIN visible_locations vl ON vl.id=l.id WHERE t.system_key='ARPA_DIVISION' AND l.approval_status='APPROVED') arpa_divisions,
          (SELECT COUNT(DISTINCT oa.officer_id) FROM officer_office_assignment oa JOIN office ofc ON ofc.id=oa.office_id {$officeLocationJoin} WHERE oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE())) officers_assigned,
          (SELECT COUNT(DISTINCT oa.officer_id) FROM officer_office_assignment oa JOIN office ofc ON ofc.id=oa.office_id {$officeLocationJoin} JOIN officer f ON f.id=oa.officer_id JOIN designation des ON des.id=f.primary_designation_id AND des.system_key='ARPA_OFFICER' WHERE oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE())) arpa_officers,
          (SELECT COUNT(*) FROM arpa_division_appointment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())) current_appointments,
          ((SELECT COUNT(*) FROM arpa_division_appointment_request r JOIN visible_locations vl ON vl.id=r.asc_location_id WHERE r.workflow_status NOT IN('NATIONAL_APPROVED','REJECTED'))+(SELECT COUNT(*) FROM arpa_subject_assignment_request r JOIN visible_locations vl ON vl.id=r.asc_location_id WHERE r.workflow_status NOT IN('NATIONAL_APPROVED','REJECTED'))) pending_requests,
          (SELECT COUNT(*) FROM arpa_subject_assignment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())) subject_assignments,
          (SELECT COUNT(DISTINCT a.subject_id) FROM arpa_subject_assignment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())) current_subjects,
          (SELECT COUNT(DISTINCT a.arpa_division_location_id) FROM arpa_division_appointment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())) covered_divisions";
        $stmt=$this->pdo->prepare($metricsSql);$stmt->execute($params);$counts=array_map('intval',$stmt->fetch());$counts['uncovered_divisions']=max(0,$counts['arpa_divisions']-$counts['covered_divisions']);
        $mix=$this->grouped($with." SELECT a.appointment_type label,COUNT(*) value FROM arpa_division_appointment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE()) GROUP BY a.appointment_type",$params);
        $subjects=$this->grouped($with." SELECT CASE WHEN a.subject_kind_snapshot IN('AGRARIAN_BANK','SALES_SHOP','SITHAMU') THEN a.subject_kind_snapshot ELSE 'OTHER_NORMAL' END label,COUNT(*) value FROM arpa_subject_assignment a JOIN visible_locations vl ON vl.id=a.asc_location_id LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE()) GROUP BY label",$params);
        $legacy=null;if($includeLegacy){$stmt=$this->pdo->prepare($with." SELECT COUNT(*) total,COALESCE(SUM(p.current_classification='HISTORICAL'),0) historical,COALESCE(SUM(p.current_classification='CURRENT'),0) current_candidates,COALESCE(SUM(p.diagnostic_blocker=1),0) blockers FROM legacy_arpa_appointment_preview p LEFT JOIN legacy_arpa_reconciliation_item si ON si.reconciled_business_key=p.reconciled_business_key AND si.item_type='SPECIAL_ASC' AND si.active=1 LEFT JOIN legacy_arpa_appointment_resolution sr ON sr.reconciliation_item_id=si.id LEFT JOIN legacy_arpa_reconciliation_item mi ON mi.reconciled_business_key=p.reconciled_business_key AND mi.item_type='MISSING_ARPA_LOCATION' AND mi.active=1 LEFT JOIN legacy_arpa_appointment_resolution mr ON mr.reconciliation_item_id=mi.id JOIN visible_locations vl ON vl.id=COALESCE(sr.selected_target_asc_id,mr.selected_target_asc_id,p.asc_location_id) WHERE p.active=1");$stmt->execute($params);$legacy=array_map('intval',$stmt->fetch());}
        return ['mode'=>'SCOPED','profile'=>$profile,'counts'=>$counts,'charts'=>['appointment_mix'=>$mix,'division_coverage'=>['Total ARPA Divisions'=>$counts['arpa_divisions'],'With Current Officer'=>$counts['covered_divisions'],'Without Current Officer'=>$counts['uncovered_divisions']],'subject_mix'=>$subjects],'legacy'=>$legacy];
    }

    public function enterpriseCounts():array
    {
        $users=$this->pdo->query("SELECT COUNT(*) total_user_identities,COALESCE(SUM(identity_type='HISTORICAL'),0) historical_users,COALESCE(SUM(identity_type<>'HISTORICAL' AND enabled=1 AND account_status='ACTIVE'),0) active_users FROM system_user")->fetch();
        return ['locations'=>(int)$this->pdo->query("SELECT COUNT(*) FROM location WHERE approval_status='APPROVED'")->fetchColumn(),'offices'=>(int)$this->pdo->query("SELECT COUNT(*) FROM office WHERE approval_status='APPROVED'")->fetchColumn(),'officers'=>(int)$this->pdo->query("SELECT COUNT(*) FROM officer WHERE approval_status='APPROVED'")->fetchColumn(),'active_users'=>(int)$users['active_users'],'historical_users'=>(int)$users['historical_users'],'total_user_identities'=>(int)$users['total_user_identities']];
    }

    public function arpaModuleCounts(string $userId): array
    {
        $restricted=ScopeService::requiresGeographicRestriction($userId);
        $with=$restricted?ScopeService::visibleLocationsCte($userId):'';
        $join=$restricted?' JOIN visible_locations vl ON vl.id=x.asc_location_id':'';
        $sql=$with." SELECT
          COALESCE(SUM(x.record_kind='DIVISION' AND x.appointment_type='PERMANENT'),0) openPermanent,
          COALESCE(SUM(x.record_kind='DIVISION' AND x.appointment_type='ACTING'),0) openActing,
          COALESCE(SUM(x.record_kind='DIVISION' AND x.appointment_type='DUTY_COVERING'),0) openDutyCovering,
          COALESCE(SUM(x.record_kind='DIVISION' AND x.appointment_type='ATTEND_TO_DUTY'),0) openAttendToDuty,
          COALESCE(SUM(x.record_kind='SUBJECT'),0) openSubjects
        FROM (
          SELECT 'DIVISION' record_kind,a.appointment_type,a.asc_location_id FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND c.id IS NULL
          UNION ALL
          SELECT 'SUBJECT',NULL,a.asc_location_id FROM arpa_subject_assignment a LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.legacy_history_only=0 AND c.id IS NULL
        ) x{$join}";
        $params=$restricted?ScopeService::visibleLocationParams($userId):[];$stmt=$this->pdo->prepare($sql);$stmt->execute($params);$row=array_map('intval',$stmt->fetch()?:[]);
        $row['pending']=(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount($userId);
        $vacancySql=$with.' SELECT COUNT(DISTINCT v.id) FROM '.ArpaAppointmentReadService::vacantDivisionSource().' v '.($restricted?'JOIN visible_locations vl ON vl.id=v.asc_location_id':'');$stmt=$this->pdo->prepare($vacancySql);$stmt->execute($params);$row['vacantDivisions']=(int)$stmt->fetchColumn();
        $issueJoin=$restricted?'JOIN visible_locations vl ON vl.id=q.asc_location_id ':'';$issueSql=$with.' SELECT COUNT(DISTINCT q.row_key) FROM '.ArpaAppointmentReadService::issueSource().' q '.$issueJoin.'WHERE '.ArpaAppointmentReadService::currentActionIssuePredicate('q');$stmt=$this->pdo->prepare($issueSql);$stmt->execute($params);$row['issues']=(int)$stmt->fetchColumn();
        $row['appointmentMix']=['Permanent'=>$row['openPermanent']??0,'Acting'=>$row['openActing']??0,'Duty Covering'=>$row['openDutyCovering']??0,'Attend to the Duty'=>$row['openAttendToDuty']??0];
        $filled=array_sum($row['appointmentMix']);$row['divisionCoverage']=['Filled / Open Appointment'=>$filled,'Vacant'=>$row['vacantDivisions']];
        return $row;
    }
    private function grouped(string $sql,array $params):array{$s=$this->pdo->prepare($sql);$s->execute($params);$out=[];foreach($s->fetchAll() as $r)$out[$r['label']]=(int)$r['value'];return $out;}
}
