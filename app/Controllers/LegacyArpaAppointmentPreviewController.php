<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Controller,DataTableRegistry,Database,LegacyDatabase,ScopeService};
use App\Services\LegacyAppointment\LegacyArpaAppointmentPreviewService;

final class LegacyArpaAppointmentPreviewController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('arpa.legacy-preview.view');$service=$this->service();$userId=(string)Auth::user()['id'];$summary=$service->summaryForUser($userId);
        $options=[
            'officer_id'=>$this->options($this->scopedOfficerOptions($userId),fn($r)=>$r['dad_number'].' - '.$r['name_with_initials']),
            'province'=>$this->options(ScopeService::scopedLocations($userId,'PROVINCE')),
            'district'=>$this->options(ScopeService::scopedLocations($userId,'DISTRICT')),
            'asc'=>$this->options(ScopeService::scopedLocations($userId,'ASC')),
            'arpa_division'=>$this->options(ScopeService::scopedLocations($userId,'ARPA_DIVISION')),
            'workflow_state'=>$this->distinct('workflow_state'),
            'reconciliation_status'=>['PENDING'=>'Pending','CONFIRMED'=>'Confirmed','UNRESOLVED_HISTORICAL'=>'Unresolved - Historical Only','REQUIRES_FURTHER_REVIEW'=>'Further Review'],
        ];
        $table=DataTableRegistry::viewModel('legacy-arpa-appointment-preview',[],$options);$this->render('arpa_appointments/legacy_preview/index',compact('summary','table'));
    }

    public function detail(string $id): void
    {
        Auth::requirePermission('arpa.legacy-preview.view');$service=$this->service();$record=$service->record($id);$this->assertRecordScope($record);$this->render('arpa_appointments/legacy_preview/detail',compact('record'));
    }

    public function officerHistory(string $id): void
    {
        Auth::requirePermission('arpa.legacy-preview.view');$service=$this->service();$history=$service->officerHistory($id);$user=Auth::user();if($user&&ScopeService::requiresGeographicRestriction((string)$user['id']))$history=array_values(array_filter($history,fn($row)=>!empty($row['asc_location_id'])&&ScopeService::canAccessLocation((string)$user['id'],(string)$row['asc_location_id'])));if($history===[]){http_response_code(404);$this->render('partials/not-found');return;}$record=$service->record((string)$history[0]['reconciled_business_key']);$this->assertRecordScope($record);$this->render('arpa_appointments/legacy_preview/officer_history',compact('record','history'));
    }

    private function service(): LegacyArpaAppointmentPreviewService{return new LegacyArpaAppointmentPreviewService(LegacyDatabase::pdo(),Database::pdo());}
    private function options(array $rows,?callable $label=null): array{$out=[];foreach($rows as $row)$out[(string)$row['id']]=$label?$label($row):$row['dad_number'].' - '.$row['name_en'];return $out;}
    private function distinct(string $column): array{$allowed=['workflow_state'];if(!in_array($column,$allowed,true))return [];$out=[];foreach(Database::pdo()->query("SELECT DISTINCT `{$column}` value FROM legacy_arpa_appointment_preview ORDER BY value")->fetchAll() as $r)$out[$r['value']]=str_replace('_',' ',$r['value']);return $out;}
    private function scopedOfficerOptions(string $userId):array{$restricted=ScopeService::requiresGeographicRestriction($userId);$with=$restricted?ScopeService::visibleLocationsCte($userId):'';$join=$restricted?' JOIN visible_locations vl ON vl.id=p.asc_location_id':'';$s=Database::pdo()->prepare($with." SELECT DISTINCT o.id,o.dad_number,o.name_with_initials FROM legacy_arpa_appointment_preview p{$join} JOIN officer o ON o.id=p.officer_id WHERE p.active=1 ORDER BY o.name_with_initials");$s->execute($restricted?ScopeService::visibleLocationParams($userId):[]);return $s->fetchAll();}
    private function assertRecordScope(array $record):void{$user=Auth::user();if(!$user||!ScopeService::requiresGeographicRestriction((string)$user['id']))return;$asc=(string)($record['confirmed_asc_id']?:$record['asc_location_id']);if($asc===''||!ScopeService::canAccessLocation((string)$user['id'],$asc)){http_response_code(404);$this->render('partials/not-found');exit;}}
}
