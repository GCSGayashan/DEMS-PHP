<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;
use PDO;
use Throwable;

/** Safe, repeatable batch coordinator for imported open ARPA appointments. */
final class ArpaAppointmentBulkCanonicalizationService
{
    public const BATCH_SIZE=200;
    private const SUMMARY_KEYS=[
        'ELIGIBLE','SKIPPED_CONFLICTING_CURRENT_APPOINTMENT','SKIPPED_ACTIVE_WORKFLOW_RESERVATION',
        'SKIPPED_GENUINE_HISTORICAL_EXCEPTION','SKIPPED_INVALID_COMBINATION','SKIPPED_OTHER_DATA_ISSUE',
    ];

    public function __construct(private readonly PDO $pdo){}

    public function canAccess():bool{return ArpaAdministrativePolicy::isCanonicalDemsAdmin();}

    /** @return array<string,mixed> */
    public function preview(int $page=1,int $perPage=100):array
    {
        $this->assertAccess();$page=max(1,$page);$perPage=max(25,min(200,$perPage));
        $rows=(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canonicalPromotionCandidates();
        $summary=array_fill_keys(self::SUMMARY_KEYS,0);
        foreach($rows as $row){$key=(string)$row['classification'];$summary[$key]=($summary[$key]??0)+1;}
        $total=count($rows);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        return [
            'rows'=>array_slice($rows,($page-1)*$perPage,$perPage),
            'summary'=>$summary,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage,
            'batch_size'=>self::BATCH_SIZE,
        ];
    }

    /** @return array<string,mixed> */
    public function execute(string $actorId):array
    {
        $this->assertAccess();
        if((string)(Auth::user()['id']??'')!==$actorId)throw new DomainException('The authenticated administrator does not match the reconciliation actor.');
        $batchId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $correction=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $eligible=array_values(array_filter($correction->canonicalPromotionCandidates(),static fn(array $row):bool=>!empty($row['eligible'])));
        $selected=array_slice($eligible,0,self::BATCH_SIZE);$results=[];$promoted=0;$skipped=0;$failed=0;
        foreach($selected as $row){
            $appointmentId=(string)$row['id'];
            try{
                $fresh=$correction->canonicalPromotionAssessment($appointmentId);
                if(empty($fresh['eligible'])){
                    $skipped++;$results[]=['appointment_id'=>$appointmentId,'result'=>'SKIPPED','reason'=>$fresh['blocker_reason']??'Eligibility changed before execution.'];continue;
                }
                $result=$correction->promoteCanonicalAppointmentFromBulk($appointmentId,$actorId,$batchId);
                $promoted++;$results[]=['appointment_id'=>$appointmentId,'result'=>'PROMOTED','reason'=>'Promoted','correction_id'=>$result['correction_id']??null];
            }catch(DomainException $e){
                $skipped++;$results[]=['appointment_id'=>$appointmentId,'result'=>'SKIPPED','reason'=>$e->getMessage()];
            }catch(Throwable $e){
                $failed++;error_log('ARPA bulk canonical reconciliation failed: batch='.$batchId.' appointment='.$appointmentId.' class='.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
                $results[]=['appointment_id'=>$appointmentId,'result'=>'FAILED','reason'=>'Unexpected processing failure; see the server log.'];
            }
        }
        return [
            'batch_id'=>$batchId,'promoted'=>$promoted,'skipped'=>$skipped,'failed'=>$failed,
            'processed'=>count($selected),'eligible_before_execution'=>count($eligible),
            'remaining_before_recalculation'=>max(0,count($eligible)-count($selected)),'results'=>$results,
        ];
    }

    private function assertAccess():void
    {
        if(!$this->canAccess())throw new DomainException('Only the canonical dems.admin account may access bulk legacy current reconciliation.');
    }
}
