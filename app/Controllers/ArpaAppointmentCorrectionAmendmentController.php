<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Controller,Csrf,Database};
use App\Services\{ArpaAppointmentCorrectionAmendmentFeature,ArpaAppointmentCorrectionAmendmentService,ArpaAppointmentDataIssueCorrectionService};
use DomainException;
use Throwable;

final class ArpaAppointmentCorrectionAmendmentController extends Controller
{
    public function create(string $sourceCorrectionId):void
    {
        if(!$this->available())return;
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);
        try{
            $actor=$this->actor();$eligibility=$this->service()->eligibility($sourceCorrectionId,$actor,true);
            if(!$eligibility['eligible']){$this->ineligible($eligibility);return;}
            $this->renderForm('create',$sourceCorrectionId,$eligibility,null,$this->emptyForm(),null);
        }catch(DomainException $e){$this->forbidden($e->getMessage());}
    }

    public function store(string $sourceCorrectionId):void
    {
        if(!$this->available())return;
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);Csrf::validate();
        $form=$this->postedForm();
        try{
            $actor=$this->actor();$context=$this->service()->eligibility($sourceCorrectionId,$actor,true);
            if(!$context['eligible']){$this->ineligible($context);return;}
            $request=$this->service()->createDraft($sourceCorrectionId,$this->serviceInput($form,(string)$context['baseline']['appointment']['id']),$actor);
            $this->flash('success','Correction amendment draft saved. No appointment information was changed.');
            redirect('/hr/arpa-appointments/correction-amendments/'.$request['id']);
        }catch(DomainException $e){
            if(!isset($context)){$this->forbidden($e->getMessage());return;}
            $context=$context??$this->safeEligibility($sourceCorrectionId,true);
            http_response_code(422);$this->renderForm('create',$sourceCorrectionId,$context,null,$form,$e->getMessage());
        }catch(Throwable $e){
            error_log('ARPA correction amendment draft creation failed: '.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
            $context=$context??$this->safeEligibility($sourceCorrectionId,true);
            http_response_code(500);$this->renderForm('create',$sourceCorrectionId,$context,null,$form,'The draft could not be saved. No appointment information was changed.');
        }
    }

    public function show(string $id):void
    {
        if(!$this->available())return;
        Auth::requirePermission('arpa.appointment.view');
        try{$request=$this->service()->interfaceGet($id,$this->actor());$this->render('arpa_appointments/correction_amendments/show',compact('request'));}
        catch(DomainException $e){$this->forbidden($e->getMessage());}
    }

    public function pending():void
    {
        if(!$this->available())return;Auth::requirePermission('arpa.appointment.view');
        $this->renderList('Pending Correction Approvals','arpa-correction-amendments-pending','Correction amendment proposals currently awaiting an action permitted by your Active Working Context.');
    }

    public function completed():void
    {
        if(!$this->available())return;Auth::requirePermission('arpa.appointment.view');
        $this->renderList('My Completed Correction Reviews','arpa-correction-amendments-completed','Your completed correction-amendment review actions within your current role, stage, and scope.');
    }

    public function edit(string $id):void
    {
        if(!$this->available())return;
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);
        try{
            $actor=$this->actor();$request=$this->service()->get($id,$actor);
            if(!in_array((string)$request['workflow_status'],['DRAFT','RETURNED'],true))throw new DomainException('Only a Draft or Returned correction amendment can be edited.');
            $context=$this->service()->eligibility((string)$request['source_correction_id'],$actor,true);
            $this->renderForm('edit',(string)$request['source_correction_id'],$context,$request,$this->formFromRequest($request),null);
        }catch(DomainException $e){$this->forbidden($e->getMessage());}
    }

    public function update(string $id):void
    {
        if(!$this->available())return;
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);Csrf::validate();
        $form=$this->postedForm();
        try{
            $actor=$this->actor();$request=$this->service()->get($id,$actor);
            $updated=$this->service()->updateDraft($id,$this->serviceInput($form,(string)$request['appointment_id']),$this->version($_POST['version']??null),$actor);
            $this->flash('success','Correction amendment draft updated. No appointment information was changed.');
            redirect('/hr/arpa-appointments/correction-amendments/'.$updated['id']);
        }catch(DomainException $e){
            try{
                $request=$this->service()->get($id,$this->actor());
                $context=$this->service()->eligibility((string)$request['source_correction_id'],$this->actor(),true);
                http_response_code(422);$this->renderForm('edit',(string)$request['source_correction_id'],$context,$request,$form,$e->getMessage());
            }catch(DomainException){$this->forbidden($e->getMessage());}
        }catch(Throwable $e){
            error_log('ARPA correction amendment draft update failed: '.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
            $this->flash('danger','The draft could not be updated. No appointment information was changed.');redirect('/hr/arpa-appointments/correction-amendments/'.$id);
        }
    }

    public function withdraw(string $id):void
    {
        if(!$this->available())return;
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);Csrf::validate();
        try{
            $request=$this->service()->withdraw($id,$this->version($_POST['version']??null),$this->actor(),$_POST['comments']??null);
            $this->flash('success','Correction amendment draft withdrawn. The original correction decision remains effective.');
            redirect('/hr/arpa-appointments/correction-amendments/'.$request['id']);
        }catch(DomainException $e){
            try{$this->service()->get($id,$this->actor());}
            catch(DomainException){$this->forbidden($e->getMessage());return;}
            $this->flash('danger',$e->getMessage());redirect('/hr/arpa-appointments/correction-amendments/'.$id);
        }
        catch(Throwable $e){error_log('ARPA correction amendment withdrawal failed: '.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());$this->flash('danger','The draft could not be withdrawn.');redirect('/hr/arpa-appointments/correction-amendments/'.$id);}
    }

    public function submit(string $id):void
    {
        if(!$this->available())return;Auth::requirePermission('arpa.appointment.submit');Csrf::validate();
        try{
            $request=$this->service()->submit($id,$this->version($_POST['version']??null),$this->actor());
            $this->flash('success','Correction amendment submitted for ASC verification. The appointment remains unchanged.');
            redirect('/hr/arpa-appointments/correction-amendments/'.$request['id']);
        }catch(DomainException $e){$this->mutationFailure($id,$e->getMessage());}
        catch(Throwable $e){error_log('ARPA correction amendment submission failed: '.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());$this->mutationFailure($id,'The correction amendment could not be submitted. No appointment information was changed.');}
    }

    public function review(string $id):void
    {
        if(!$this->available())return;Auth::requirePermission('arpa.appointment.view');Csrf::validate();
        $action=strtoupper(trim((string)($_POST['action']??'')));
        try{
            $interface=$this->service()->interfaceGet($id,$this->actor());
            if($action==='APPROVE'&&!empty($interface['interface_actions']['final_approval'])&&($_POST['confirm_apply']??'')!=='1'){
                throw new DomainException('Confirm that final National approval may apply the displayed correction.');
            }
            $request=$this->service()->review($id,$action,$this->version($_POST['version']??null),$this->actor(),$_POST['reason']??null);
            $message=(string)$request['workflow_status']==='NATIONAL_APPROVED'
                ?'Correction amendment approved and applied successfully.'
                :'Correction amendment review action completed.';
            $this->flash('success',$message);redirect('/hr/arpa-appointments/correction-amendments/'.$request['id']);
        }catch(DomainException $e){$this->mutationFailure($id,$e->getMessage());}
        catch(Throwable $e){error_log('ARPA correction amendment review failed: '.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());$this->mutationFailure($id,'The review action could not be completed. No partial correction was applied.');}
    }

    private function available():bool
    {
        if(!ArpaAppointmentCorrectionAmendmentFeature::enabled()){http_response_code(404);$this->render('partials/not-found');return false;}
        if(!ArpaAppointmentCorrectionAmendmentFeature::schemaAvailable(Database::pdo())){
            http_response_code(503);$this->render('arpa_appointments/correction_amendments/unavailable');return false;
        }
        return true;
    }

    /** @param array<string,mixed> $eligibility @param array<string,mixed>|null $request @param array<string,mixed> $form */
    private function renderForm(string $mode,string $sourceCorrectionId,array $eligibility,?array $request,array $form,?string $error):void
    {
        $this->render('arpa_appointments/correction_amendments/form',compact('mode','sourceCorrectionId','eligibility','request','form','error'));
    }

    private function renderList(string $title,string $key,string $description):void
    {
        $this->render('arpa_appointments/list',[
            'title'=>$title,'description'=>$description,
            'dataTable'=>\App\Core\DataTableRegistry::viewModel($key),
            'createUrl'=>null,'createPermission'=>null,'createLabel'=>null,
        ]);
    }

    private function mutationFailure(string $id,string $message):never
    {
        try{$this->service()->interfaceGet($id,$this->actor());}
        catch(DomainException){$this->forbidden($message);exit;}
        $this->flash('danger',$message);redirect('/hr/arpa-appointments/correction-amendments/'.$id);
    }

    /** @return array<string,mixed> */
    private function postedForm():array
    {
        return [
            'proposed_action'=>trim((string)($_POST['proposed_action']??'')),
            'effective_from'=>trim((string)($_POST['effective_from']??'')),
            'effective_to'=>trim((string)($_POST['effective_to']??'')),
            'appointment_type'=>trim((string)($_POST['appointment_type']??'')),
            'arpa_division_location_id'=>trim((string)($_POST['arpa_division_location_id']??'')),
            'end_reason_id'=>trim((string)($_POST['end_reason_id']??'')),
            'correction_reason'=>trim((string)($_POST['correction_reason']??'')),
            'remarks'=>trim((string)($_POST['remarks']??'')),
            'evidence_reference'=>trim((string)($_POST['evidence_reference']??'')),
        ];
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private function serviceInput(array $form,string $linkedAppointmentId):array
    {
        $action=strtoupper((string)$form['proposed_action']);$proposed=[];
        if($action==='SET_EFFECTIVE_TO')$proposed=['effective_to'=>$form['effective_to'],'end_reason_id'=>$this->nullable($form['end_reason_id'])];
        elseif($action==='CORRECT_APPOINTMENT_TYPE')$proposed=['appointment_type'=>$form['appointment_type']];
        elseif($action==='CORRECT_ARPA_DIVISION')$proposed=['arpa_division_location_id'=>$form['arpa_division_location_id']];
        elseif($action==='CORRECT_EFFECTIVE_FROM')$proposed=['effective_from'=>$form['effective_from']];
        elseif($action==='CORRECT_END_REASON')$proposed=['end_reason_id'=>$form['end_reason_id']];
        elseif($action==='SELECT_CURRENT_RECORD')$proposed=['appointment_id'=>$linkedAppointmentId];
        return ['proposed_action'=>$action,'proposed_json'=>$proposed,'correction_reason'=>$form['correction_reason'],'remarks'=>$this->nullable($form['remarks']),'evidence_reference'=>$this->nullable($form['evidence_reference'])];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function formFromRequest(array $request):array
    {
        $proposed=$request['proposed'];
        return ['proposed_action'=>$request['proposed_action'],'effective_from'=>$proposed['effective_from']??'','effective_to'=>$proposed['effective_to']??'','appointment_type'=>$proposed['appointment_type']??'','arpa_division_location_id'=>$proposed['arpa_division_location_id']??'','end_reason_id'=>$proposed['end_reason_id']??'','correction_reason'=>$request['correction_reason'],'remarks'=>$request['remarks']??'','evidence_reference'=>$request['evidence_reference']??''];
    }

    /** @return array<string,string> */
    private function emptyForm():array
    {
        return ['proposed_action'=>'','effective_from'=>'','effective_to'=>'','appointment_type'=>'','arpa_division_location_id'=>'','end_reason_id'=>'','correction_reason'=>'','remarks'=>'','evidence_reference'=>''];
    }

    /** @return array<string,mixed> */
    private function safeEligibility(string $sourceCorrectionId,bool $includeOptions):array
    {
        try{return $this->service()->eligibility($sourceCorrectionId,$this->actor(),$includeOptions);}
        catch(Throwable){return ['eligible'=>false,'reason'=>'The source correction is unavailable.','baseline'=>null,'appointment_types'=>[],'end_reasons'=>[],'arpa_divisions'=>[]];}
    }

    /** @param array<string,mixed> $eligibility */
    private function ineligible(array $eligibility):void
    {
        if(!empty($eligibility['active_amendment_id']))redirect('/hr/arpa-appointments/correction-amendments/'.$eligibility['active_amendment_id']);
        http_response_code(409);$message=(string)($eligibility['reason']??'This correction decision is not eligible for an amendment draft.');
        $this->render('arpa_appointments/correction_amendments/ineligible',compact('message'));
    }

    private function forbidden(string $message):void
    {
        http_response_code(403);$this->render('partials/forbidden',['permission'=>$message]);
    }

    private function service():ArpaAppointmentCorrectionAmendmentService{return new ArpaAppointmentCorrectionAmendmentService(Database::pdo());}
    private function actor():string{return (string)(Auth::user()['id']??'');}
    private function nullable(mixed $value):?string{$value=trim((string)$value);return $value===''?null:$value;}
    private function version(mixed $value):int{$value=(string)$value;return ctype_digit($value)?(int)$value:-1;}
}
