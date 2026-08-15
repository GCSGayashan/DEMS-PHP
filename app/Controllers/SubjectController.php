<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Audit,Auth,Controller,Csrf,Database,DataTableRegistry};
use App\Services\{ArpaAppointmentRules,ArpaAppointmentService};
use DomainException;
use Throwable;

final class SubjectController extends Controller
{
    public function index():void
    {
        Auth::requirePermission('subject.master.view');$dataTable=DataTableRegistry::viewModel('subjects');$this->render('subjects/index',compact('dataTable'));
    }

    public function create():void
    {
        Auth::requirePermission('subject.master.create');$this->render('subjects/form',['subject'=>null]);
    }

    public function store():void
    {
        Auth::requirePermission('subject.master.create');Csrf::validate();
        try{(new ArpaAppointmentService(Database::pdo()))->createSubject((string)($_POST['name_en']??''),(string)($_POST['subject_kind']??''),(string)($_POST['system_key']??''),(string)Auth::user()['id'],$_POST['name_si']??null,$_POST['name_ta']??null);$this->flash('success','Central Subject Master created.');redirect('/subjects');}
        catch(Throwable $e){error_log('Subject master create failed: '.$e->getMessage());$this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to create Subject Master.');redirect('/subjects/create');}
    }

    public function edit(string $id):void
    {
        Auth::requirePermission('subject.master.edit');$s=Database::pdo()->prepare('SELECT * FROM subject_master WHERE id=?');$s->execute([$id]);$subject=$s->fetch();if(!$subject){http_response_code(404);exit('Subject Master not found.');}$this->render('subjects/form',compact('subject'));
    }

    public function update(string $id):void
    {
        Auth::requirePermission('subject.master.edit');Csrf::validate();
        try{$name=trim((string)($_POST['name_en']??''));$kind=strtoupper(trim((string)($_POST['subject_kind']??'')));$active=($_POST['active']??'0')==='1'?1:0;if($name===''||!in_array($kind,ArpaAppointmentRules::SUBJECT_KINDS,true))throw new DomainException('Name and a supported subject kind are required.');$pdo=Database::pdo();$s=$pdo->prepare('SELECT system_key FROM subject_master WHERE id=?');$s->execute([$id]);$key=$s->fetchColumn();if(!$key)throw new DomainException('Subject Master was not found.');if((in_array($kind,ArpaAppointmentRules::EXCLUSIVE_SUBJECT_KINDS,true)||in_array($key,ArpaAppointmentRules::EXCLUSIVE_SUBJECT_KINDS,true))&&$kind!==$key)throw new DomainException('The kind of a protected system-recognized subject cannot be changed or duplicated under another key.');$pdo->prepare('UPDATE subject_master SET name_en=?,name_si=?,name_ta=?,subject_kind=?,active=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$name,$this->null($_POST['name_si']??null),$this->null($_POST['name_ta']??null),$kind,$active,Auth::user()['id'],$id]);Audit::record('subject.master.update','SUBJECT_MASTER',$id,['system_key'=>$key,'active'=>$active]);$this->flash('success','Central Subject Master updated.');redirect('/subjects');}
        catch(Throwable $e){error_log('Subject master update failed: '.$e->getMessage());$this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to update Subject Master.');redirect('/subjects/'.$id.'/edit');}
    }

    private function null(mixed $value):?string{$value=trim((string)$value);return $value===''?null:$value;}
}
