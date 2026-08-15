<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Controller,Csrf,Database,DataTableRegistry,LegacyDatabase};
use App\Services\LegacyAppointment\LegacyArpaReconciliationService;
use DomainException;
use Throwable;

final class LegacyArpaReconciliationController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.view');
        $service=$this->service();$dashboard=$service->dashboard();
        $options=[
            'subject_kind'=>['AGRARIAN_BANK'=>'Agrarian Bank','SALES_SHOP'=>'Sales Shop','SITHAMU'=>'Sithamu'],
            'current_classification'=>['CURRENT'=>'Current','HISTORICAL'=>'Historical'],
            'source_confidence'=>['STRONG_DERIVED'=>'Strong Derived','CURRENT_STATE_ONLY'=>'Current State Only','UNRESOLVED'=>'Unresolved','MISSING'=>'Missing'],
            'resolution_status'=>['PENDING'=>'Pending','CONFIRMED'=>'Confirmed','UNRESOLVED_HISTORICAL'=>'Unresolved - Historical Only','REQUIRES_FURTHER_REVIEW'=>'Further Review'],
            'source_table'=>['tbl_officer_apoint'=>'Old','tbl_officer_apoint_2026'=>'2026'],
        ];
        $specialTable=DataTableRegistry::viewModel('legacy-arpa-special-review',[],$options,['current_classification'=>'CURRENT']);
        $groupTable=DataTableRegistry::viewModel('legacy-arpa-special-groups',[],$options,['resolution_status'=>'PENDING']);
        $missingTable=DataTableRegistry::viewModel('legacy-arpa-missing-location-review',[],$options);
        $conflictTable=DataTableRegistry::viewModel('legacy-arpa-current-conflicts',[],$options);
        $this->render('arpa_appointments/legacy_review/index',compact('dashboard','specialTable','groupTable','missingTable','conflictTable'));
    }

    public function detail(string $id): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.view');$service=$this->service();$item=$service->item($id);$audit=$service->auditHistory($id);$ascs=$service->locations('ASC');$arpaDivisions=$service->locations('ARPA_DIVISION');$this->render('arpa_appointments/legacy_review/detail',compact('item','audit','ascs','arpaDivisions'));
    }

    public function decide(string $id): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.decide');Csrf::validate();
        try{$this->service()->decide($id,$_POST,(string)Auth::user()['id']);$this->flash('success','Reconciliation decision saved with immutable audit history.');}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());}
        catch(Throwable $e){error_log($e->__toString());$this->flash('danger','Unable to save the reconciliation decision. Please try again.');}
        redirect('/hr/arpa-appointments/legacy-review/items/'.$id);
    }

    public function bulkConfirmStrong(): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.decide');Csrf::validate();
        try{$result=$this->service()->bulkConfirmStrongDerived((string)Auth::user()['id'],(string)($_POST['decision_reason']??''),($_POST['confirm_bulk']??'')==='1');$this->flash('success',"Created {$result['created']} explicit decisions in audited batch {$result['bulk_operation_id']}. Source evidence remains unchanged.");}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());}
        catch(Throwable $e){error_log($e->__toString());$this->flash('danger','Unable to complete the bulk confirmation. Please try again.');}
        redirect('/hr/arpa-appointments/legacy-review');
    }

    public function group(): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.view');
        try{$candidate=trim((string)($_GET['candidate_asc_id']??''));$group=$this->service()->group((string)($_GET['officer_id']??''),strtoupper(trim((string)($_GET['function']??''))),$candidate===''?null:$candidate);$this->render('arpa_appointments/legacy_review/group',compact('group'));}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/arpa-appointments/legacy-review');}
    }

    public function confirmGroup(): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.decide');Csrf::validate();
        try{$result=$this->service()->confirmSelectedGroup((array)($_POST['item_ids']??[]),(string)Auth::user()['id'],(string)($_POST['decision_reason']??''),($_POST['confirm_group']??'')==='1');$this->flash('success',"Created {$result['created']} record-level decisions in audited group batch {$result['bulk_operation_id']}.");}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());}
        catch(Throwable $e){error_log($e->__toString());$this->flash('danger','Unable to confirm the selected reconciliation group.');}
        redirect('/hr/arpa-appointments/legacy-review');
    }

    public function refresh(): void
    {
        Auth::requirePermission('arpa.legacy-reconciliation.decide');Csrf::validate();
        try{$result=$this->service()->refresh((string)Auth::user()['id']);$this->flash('success',"Review queues refreshed: {$result['special']} special, {$result['missing_arpa']} missing location, {$result['conflicts']} conflicts.");}
        catch(Throwable $e){error_log($e->__toString());$this->flash('danger','Unable to refresh review queues. Please try again.');}
        redirect('/hr/arpa-appointments/legacy-review');
    }

    private function service(): LegacyArpaReconciliationService{return new LegacyArpaReconciliationService(LegacyDatabase::pdo(),Database::pdo());}
}
