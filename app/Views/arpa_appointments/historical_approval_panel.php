<?php
// Retain the existing native table and add a separately authorized historical-case table.
$tableId=$dataTable['id']??'';$historicalPending=str_contains($tableId,'arpa-submitted-appointments');$historicalCompleted=str_contains($tableId,'arpa-approval-verification');
if($historicalPending||$historicalCompleted):
    $historyRead=new \App\Services\ArpaHistoricalApprovalReadService(\App\Core\Database::pdo());$historyActor=(string)\App\Core\Auth::user()['id'];
    if($historyRead->available()&&$historyRead->canUse($historyActor)):
        $historyContext=[];if(isset($asc['id']))$historyContext['asc_id']=$asc['id'];if(isset($district['id']))$historyContext['district_id']=$district['id'];
        $primaryTable=$dataTable;$historyCount=$historyRead->count($historyActor,$historicalCompleted,$historyContext);
        $dataTable=\App\Core\DataTableRegistry::viewModel($historicalCompleted?'arpa-historical-approvals-completed':'arpa-historical-approvals-pending',$historyContext);
?>
<section class="mt-4"><h2 class="h5">Historical Appointment <?= $historicalCompleted?'Completed Reviews &amp; Approvals':'Pending Review &amp; Approval' ?></h2><p>Historical <?= $historicalCompleted?'completed actions':'actionable cases' ?>: <strong><?= $historyCount ?></strong>. These are included in the hierarchy summary totals. Original appointment and correction data remain unchanged.</p>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?></section>
<?php $dataTable=$primaryTable;endif;endif; ?>
