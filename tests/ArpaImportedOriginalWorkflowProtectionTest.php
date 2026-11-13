<?php
declare(strict_types=1);

// Isolate the real service/controller/view from accounts, notifications and all databases.
namespace App\Core {
    final class Auth {
        public static function user():array{return ['id'=>'reviewer'];}
        public static function can(string $permission):bool{return true;}
        public static function requirePermission(string $permission):void{}
    }
    final class Database {
        public static \PDO $fixture;
        public static function pdo():\PDO{return self::$fixture;}
    }
    final class ScopeService {
        public static function canAccessCurrentArpaStage(string $actor,string $stage,string $asc):bool{return true;}
    }
}
namespace App\Services {
    final class WorkflowNotificationService {
        public function __construct(\PDO $pdo){}
        public function completeStage(...$arguments):void{}
        public function resolveAll(...$arguments):void{}
        public function information(...$arguments):void{}
        public function actionForUser(...$arguments):void{}
    }
    final class ArpaPaymentStopService {
        public static function afterCommit(\PDO $pdo):void{}
    }
}
namespace {
    const BASE_PATH=__DIR__.'/..';
    spl_autoload_register(static function(string $class):void{
        if(str_starts_with($class,'App\\'))require BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
    });
    function e(?string $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
    function url(string $path=''):string{return '/'.ltrim($path,'/');}
    final class WorkflowTestRedirect extends RuntimeException{}
    function redirect(string $path):never{throw new WorkflowTestRedirect($path);}

    final class ImportedWorkflowStatement extends PDOStatement {
        private array|false $row=false;
        public function __construct(private ImportedWorkflowPdo $pdo,private string $sql){}
        public function execute(?array $params=null):bool{
            $this->pdo->queries[]=$this->sql;
            if(str_starts_with($this->sql,'SELECT * FROM arpa_'))$this->row=$this->pdo->request;
            elseif(str_starts_with($this->sql,'UPDATE arpa_')){
                $this->pdo->writes++;$this->pdo->request['workflow_status']=$params[0];
            }elseif(str_starts_with($this->sql,'INSERT '))$this->pdo->writes++;
            return true;
        }
        public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;}
    }
    final class ImportedWorkflowPdo extends PDO {
        public array $queries=[];
        public int $writes=0;
        public int $rollbacks=0;
        private bool $active=false;
        private array $snapshot=[];
        public function __construct(public array $request){}
        public function prepare(string $query,array $options=[]):PDOStatement|false{return new ImportedWorkflowStatement($this,$query);}
        public function inTransaction():bool{return $this->active;}
        public function beginTransaction():bool{$this->active=true;$this->snapshot=$this->request;return true;}
        public function commit():bool{$this->active=false;return true;}
        public function rollBack():bool{$this->request=$this->snapshot;$this->active=false;$this->rollbacks++;return true;}
    }
    $assertions=0;
    function sameWorkflow(mixed $expected,mixed $actual,string $message):void{
        global $assertions;$assertions++;
        if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
    $message='Imported historical appointment. Use the Historical Approval workflow for authorization.';
    $base=['id'=>'request','request_type'=>'APPOINTMENT','workflow_status'=>'SUBMITTED','record_origin'=>'LEGACY_IMPORT',
        'created_by'=>'reviewer','asc_location_id'=>'asc','requested_effective_from'=>'2020-01-01',
        'requested_effective_to'=>null,'source_appointment_id'=>null,'officer_id'=>'officer','legacy_history_only'=>0];
    $cases=[['CREATED','SUBMIT','CREATOR'],['SUBMITTED','VERIFY','ASC'],['ASC_VERIFIED','APPROVE','ASC'],
        ['ASC_APPROVED','VERIFY','DISTRICT'],['DISTRICT_VERIFIED','APPROVE','DISTRICT'],
        ['DISTRICT_APPROVED','VERIFY','NATIONAL'],['NATIONAL_VERIFIED','APPROVE','NATIONAL']];
    foreach(['SUBMITTED'=>'ASC','ASC_APPROVED'=>'DISTRICT','DISTRICT_APPROVED'=>'NATIONAL'] as $status=>$stage){
        foreach(['RETURN_FOR_CORRECTION','REJECT'] as $action)$cases[]=[$status,$action,$stage];
    }
    foreach([0,1] as $historyOnly){
        foreach($cases as [$status,$action,$stage]){
            $row=array_replace($base,['workflow_status'=>$status,'legacy_history_only'=>$historyOnly]);
            $pdo=new ImportedWorkflowPdo($row);
            try{(new App\Services\ArpaAppointmentService($pdo))->workflow('division','request',strtolower($action),$stage,'Reason','reviewer');throw new RuntimeException('Imported transition succeeded.');}
            catch(DomainException $exception){sameWorkflow($message,$exception->getMessage(),'Service blocks imported '.$action);}
            sameWorkflow($row,$pdo->request,'Imported data unchanged');
            sameWorkflow(0,$pdo->writes,'No status/history/audit/materialization writes');
            sameWorkflow(1,$pdo->rollbacks,'Blocked transaction rolls back');
        }
    }
    // Exercise the real direct-endpoint controller with permission, scope and CSRF granted.
    foreach($cases as [$status,$action,$stage]){
        $row=array_replace($base,['workflow_status'=>$status]);$pdo=new ImportedWorkflowPdo($row);
        App\Core\Database::$fixture=$pdo;$_GET=['stage'=>$stage];$_POST=['comments'=>'Reason','_csrf'=>'valid'];$_SESSION=['_csrf'=>'valid'];
        try{(new App\Controllers\ArpaAppointmentController())->workflow('division','request',strtolower($action));}
        catch(WorkflowTestRedirect $redirect){}
        sameWorkflow($message,$_SESSION['_flash'][0]['message']??null,'Direct endpoint denies imported '.$action);
        sameWorkflow(0,$pdo->writes,'Direct endpoint cannot mutate imported request');
    }
    foreach([['division','NATIVE'],['subject','NATIVE'],['subject','LEGACY_IMPORT']] as [$entity,$origin]){
        $row=array_replace($base,['record_origin'=>$origin,'created_by'=>'','workflow_status'=>'SUBMITTED']);
        $pdo=new ImportedWorkflowPdo($row);
        sameWorkflow('RETURNED',(new App\Services\ArpaAppointmentService($pdo))->workflow($entity,'request','REJECT','ASC','Reason','reviewer'),'Existing '.$entity.'/'.$origin.' transition remains supported');
        sameWorkflow(3,$pdo->writes,'Existing transition retains request/history/audit writes');
    }
    $viewSource=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/request_detail.php');
    preg_match_all('/\$request\[\x27([^\x27]+)\x27\]/',$viewSource,$keys);
    $defaults=array_fill_keys($keys[1],'');
    foreach(['CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'] as $status){
        foreach([['division','LEGACY_IMPORT',false],['division','NATIVE',true],['subject','LEGACY_IMPORT',true]] as [$entity,$origin,$expectedButtons]){
            $request=array_replace($defaults,$base,['workflow_status'=>$status,'record_origin'=>$origin,'deleted_at'=>null]);
            $workflowHistory=[];$stageReviews=[];$impact=[];
            ob_start();require BASE_PATH.'/app/Views/arpa_appointments/request_detail.php';$html=(string)ob_get_clean();
            sameWorkflow($expectedButtons,str_contains($html,'/hr/arpa-appointments/workflow/'),'Original workflow button visibility '.$entity.'/'.$origin.'/'.$status);
            sameWorkflow(!$expectedButtons,str_contains($html,$message),'Historical Approval notice visibility');
        }
    }
    echo 'ArpaImportedOriginalWorkflowProtectionTest: '.$assertions." assertions passed; no database connection.\n";
}
