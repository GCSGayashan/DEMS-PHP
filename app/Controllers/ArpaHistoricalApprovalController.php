<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Csrf,Database};
use App\Services\{ArpaHistoricalAppointmentApprovalService,ArpaHistoricalApprovalReadService};
use DomainException;
use Throwable;

final class ArpaHistoricalApprovalController extends Controller
{
    private function actor():string
    {
        Auth::requireLogin(false);$actor=(string)Auth::user()['id'];
        $read=new ArpaHistoricalApprovalReadService(Database::pdo());
        if(!$read->canUse($actor))throw new DomainException('Historical approval is outside your current Working Context.',403);
        if(!$read->available())throw new DomainException('Historical approval is unavailable until its schema is installed.',503);
        return $actor;
    }
    public function show(string $id):void
    {
        try{$actor=$this->actor();$detail=(new ArpaHistoricalAppointmentApprovalService(Database::pdo()))->interfaceGet($id,$actor);$this->render('arpa_appointments/historical_approval_detail',compact('detail'));}
        catch(Throwable $e){$this->failure($e);}
    }
    public function save(string $id):void{$this->mutate($id,'save');}
    public function submit(string $id):void{$this->mutate($id,'submit');}
    public function review(string $id):void{$this->mutate($id,'review');}
    private function mutate(string $id,string $operation):void
    {
        try{
            $actor=$this->actor();
            if(!is_string($_SESSION['_csrf']??null)||($_SESSION['_csrf']??'')===''||!is_string($_POST['_csrf']??null)||($_POST['_csrf']??'')==='')throw new DomainException('Invalid CSRF token.',419);
            Csrf::validate();
            $allowed=match($operation){'save'=>['_csrf','expected_version','expected_source_hash','reason','evidence_reference','remarks'],'review'=>['_csrf','expected_version','action','reason','evidence_reference','confirm_final'],default=>['_csrf','expected_version']};
            if(array_diff(array_keys($_POST),$allowed)!==[])throw new DomainException('Protected or unsupported historical approval input.',422);
            $raw=$_POST['expected_version']??'';if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$raw))throw new DomainException('A valid expected version is required.',422);
            $version=(int)$raw;$s=new ArpaHistoricalAppointmentApprovalService(Database::pdo());
            if($operation==='save'){
                $input=array_intersect_key($_POST,array_flip(['reason','evidence_reference','remarks']));
                if(!is_string($_POST['expected_source_hash']??null))throw new DomainException('Review the current baseline before saving.',422);
                $s->updateDraft($id,$input,$version,$_POST['expected_source_hash'],$actor);
            }elseif($operation==='submit')$s->submit($id,$version,$actor);
            else{
                $action=$_POST['action']??'';$reason=$_POST['reason']??'';$evidence=$_POST['evidence_reference']??'';
                if(!is_string($action)||!is_string($reason)||!is_string($evidence))throw new DomainException('Valid action, reason and evidence are required.',422);
                $detail=$s->interfaceGet($id,$actor);
                if(strtoupper($action)==='APPROVE'&&$detail['case']['workflow_status']==='NATIONAL_VERIFIED'&&($_POST['confirm_final']??'')!=='1')throw new DomainException('Explicitly confirm final National approval of the existing appointment.',422);
                $s->review($id,$action,$version,$actor,$reason,$evidence);
            }
            $this->flash('success','Historical Appointment case updated. Original appointment and correction data were not changed.');redirect('/hr/arpa-appointments/historical-approvals/'.$id);
        }catch(DomainException $e){
            if(in_array($e->getCode(),[403,404,419,503],true)){$this->failure($e);return;}
            try{$actor=$this->actor();$detail=(new ArpaHistoricalAppointmentApprovalService(Database::pdo()))->interfaceGet($id,$actor);http_response_code($e->getCode()===409?409:422);$input=$_POST;$error=$e->getMessage();$this->render('arpa_appointments/historical_approval_detail',compact('detail','input','error'));}
            catch(Throwable $unavailable){$this->failure($unavailable);}
        }catch(Throwable $e){$this->failure($e);}
    }
    private function failure(Throwable $e):void
    {
        $safe=$e instanceof DomainException;$code=$safe&&in_array($e->getCode(),[403,404,409,419,422,503],true)?$e->getCode():503;
        http_response_code($code);if(!$safe)error_log('Historical approval unavailable: '.get_class($e));
        echo '<div class="alert alert-danger">'.e($safe?$e->getMessage():'Historical approval is unavailable. No changes were made.').'</div>';
    }
}
