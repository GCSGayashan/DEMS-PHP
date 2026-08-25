<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Database,Csrf,NumberService,Audit,WorkflowService,DataTableRegistry};

final class HrMasterController extends Controller
{
    private const MAP = [
        'titles'=>['table'=>'hr_title','label'=>'Title','category'=>'HR_TITLE'],
        'appointment-natures'=>['table'=>'appointment_nature','label'=>'Appointment Nature','category'=>'APPOINTMENT_NATURE'],
        'designations'=>['table'=>'designation','label'=>'Designation','category'=>'DESIGNATION'],
        'classes'=>['table'=>'officer_class','label'=>'Class','category'=>'OFFICER_CLASS'],
        'officer-statuses'=>['table'=>'officer_status','label'=>'Officer Status','category'=>'OFFICER_STATUS'],
        'civil-statuses'=>['table'=>'civil_status','label'=>'Civil Status','category'=>'CIVIL_STATUS'],
    ];

    public function index(string $type): void
    {
        Auth::requirePermission('hr.master.view');
        $cfg=self::MAP[$type]??null; if(!$cfg){http_response_code(404);exit('Unknown master');}
        $dataTable=DataTableRegistry::viewModel('hr-masters',['master_type'=>$type]);
        $this->render('hr/masters',compact('type','cfg','dataTable'));
    }

    public function store(string $type): void
    {
        Auth::requirePermission('hr.master.create'); Csrf::validate();
        $cfg=self::MAP[$type]??null; if(!$cfg){http_response_code(404);exit('Unknown master');}
        $name=trim((string)($_POST['name_en']??'')); if($name===''){ $this->flash('danger','English name is required.'); redirect('/hr/'.$type); }
        $dad=NumberService::next($cfg['category']);
        $table=$cfg['table'];
        $extraCols='';$extraVals=[];$marks='';
        if($type==='appointment-natures'){ $extraCols=', class_required'; $marks=', ?'; $extraVals[]=(int)!empty($_POST['class_required']); }
        if($type==='designations'){ $extraCols=', designation_level,parent_designation_id'; $marks=', ?,?'; $extraVals[]=$_POST['designation_level']??'MAIN'; $extraVals[]=($_POST['parent_designation_id']??'')?:null; }
        $sql="INSERT INTO {$table} (id,dad_number,name_en,name_si,name_ta,description,display_order,system_key,active,effective_from,approval_status,created_by,created_at,submitted_by,submitted_at{$extraCols}) VALUES (UUID(),?,?,?,?,?,?,?,1,?,'SUBMITTED',?,NOW(),?,NOW(){$marks})";
        $actor=(string)Auth::user()['id'];
        $vals=[$dad,$name,trim((string)($_POST['name_si']??''))?:null,trim((string)($_POST['name_ta']??''))?:null,trim((string)($_POST['description']??''))?:null,(int)($_POST['display_order']??100),strtoupper(preg_replace('/[^A-Z0-9]+/','_',strtoupper($name))),(string)($_POST['effective_from']??date('Y-m-d')),$actor,$actor,...$extraVals];
        Database::pdo()->prepare($sql)->execute($vals);
        Audit::record('hr.master.create',strtoupper($table),null,['dad_number'=>$dad]);
        Audit::record('workflow.submit',strtoupper($table),null,['dad_number'=>$dad]);
        $this->flash('success',$cfg['label'].' submitted.'); redirect('/hr/'.$type);
    }

    public function submit(string $type,string $id): void { Auth::requirePermission('hr.master.edit'); Csrf::validate(); $cfg=self::MAP[$type]??null; if(!$cfg){http_response_code(404);exit;} WorkflowService::submit($cfg['table'],$id); $this->flash('success',$cfg['label'].' submitted.'); redirect('/hr/'.$type); }
    public function approve(string $type,string $id): void { Auth::requirePermission('hr.master.approve'); Csrf::validate(); $cfg=self::MAP[$type]??null; if(!$cfg){http_response_code(404);exit;} try{WorkflowService::approve($cfg['table'],$id);$this->flash('success',$cfg['label'].' approved.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());} redirect('/hr/'.$type); }
}
