<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Csrf,Database};
use App\Services\ArpaLegacyRequestExclusionService;
use DomainException;
use Throwable;

final class ArpaLegacyRequestExclusionController extends Controller
{
    private function actor():?string
    {
        if(!ArpaLegacyRequestExclusionService::allowed()){
            http_response_code(403);$this->render('partials/forbidden',['permission'=>'the canonical DEMS System Administrator / SYSTEM context']);return null;
        }
        return (string)Auth::user()['id'];
    }
    public function preview(string $id):void
    {
        if(($actor=$this->actor())===null)return;
        try{$preview=(new ArpaLegacyRequestExclusionService(Database::pdo()))->preview($id,$actor);$this->render('arpa_appointments/legacy_request_exclusion',['preview'=>$preview,'input'=>[]]);}
        catch(Throwable $e){$this->failure($e);}
    }
    public function exclude(string $id):void
    {
        if(($actor=$this->actor())===null)return;
        Csrf::validate();
        $service=new ArpaLegacyRequestExclusionService(Database::pdo());
        try{$service->exclude($id,$_POST,$actor);$this->flash('success','Legacy request excluded. Its imported evidence and workflow status are preserved.');redirect('/hr/arpa-appointments/legacy-requests/'.$id.'/exclusion');}
        catch(DomainException $e){
            if($e->getCode()===403){$this->failure($e);return;}
            try{$preview=$service->preview($id,$actor);http_response_code(in_array($e->getCode(),[404,409,422],true)?$e->getCode():422);$this->render('arpa_appointments/legacy_request_exclusion',['preview'=>$preview,'input'=>$_POST,'error'=>$e->getMessage()]);}
            catch(Throwable $unavailable){$this->failure($unavailable);}
        }catch(Throwable $e){$this->failure($e);}
    }
    public function audit(?string $id=null):void
    {
        if(($actor=$this->actor())===null)return;
        try{$rows=(new ArpaLegacyRequestExclusionService(Database::pdo()))->audit($actor,$id);$this->render('arpa_appointments/legacy_request_exclusion_audit',compact('rows'));}
        catch(Throwable $e){$this->failure($e);}
    }
    private function failure(Throwable $e):void
    {
        $controlled=$e instanceof DomainException;
        http_response_code($controlled&&in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():503);
        if(!$controlled)error_log('Legacy ARPA exclusion unavailable: '.get_class($e));
        echo '<div class="alert alert-danger">'.e($controlled?$e->getMessage():'Legacy exclusion is unavailable. No changes were made.').'</div>';
    }
}
