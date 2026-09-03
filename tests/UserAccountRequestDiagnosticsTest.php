<?php
declare(strict_types=1);

use App\Controllers\UserManagementController;
use App\Services\UserAccountRequestStageException;

require dirname(__DIR__).'/bootstrap.php';

final class UserAccountRequestDiagnosticsTest
{
    private int $assertions=0;

    public function run():int
    {
        $controller=new UserManagementController();
        $browserMessage=new ReflectionMethod($controller,'userAccountRequestBrowserMessage');
        $logFailure=new ReflectionMethod($controller,'logUserAccountRequestFailure');

        $business=new DomainException('That username is already in use.');
        $this->same('That username is already in use.',$browserMessage->invoke($controller,$business),'DomainException retains its controlled browser message');

        $pdoFailure=new PDOException('Duplicate entry reported by the database driver',23000);
        $pdoFailure->errorInfo=['23000',1062,'Driver detail intentionally excluded'];
        $unexpected=new UserAccountRequestStageException('CREATE_SYSTEM_USER',$pdoFailure);
        $this->same('Unable to submit the user request.',$browserMessage->invoke($controller,$unexpected),'unexpected PDO failure retains the generic browser message');

        $temporaryPassword='Never-Log-Temporary-Password-42!';
        $mfaSecret='Never-Log-MFA-Secret';
        $previousPost=$_POST;
        $_POST=['temporary_password'=>$temporaryPassword,'mfa_secret'=>$mfaSecret,'full_name'=>'Sensitive Person'];
        $logFile=tempnam(sys_get_temp_dir(),'dems-user-request-log-');
        if($logFile===false)throw new RuntimeException('Unable to create a temporary log fixture.');
        $oldLog=ini_get('error_log');
        try{
            ini_set('error_log',$logFile);
            $logFailure->invoke($controller,$unexpected);
            $logFailure->invoke($controller,$business);
            $log=(string)file_get_contents($logFile);
        }finally{
            ini_set('error_log',(string)$oldLog);
            $_POST=$previousPost;
            @unlink($logFile);
        }

        foreach([
            'class=PDOException','code=23000','message=Duplicate entry reported by the database driver',
            'stage=CREATE_SYSTEM_USER','sqlstate=23000','driver_code=1062',
            'class=DomainException','message=That username is already in use.',
        ] as $needle)$this->contains($needle,$log,"server log contains {$needle}");
        $this->same(false,str_contains($log,'Driver detail intentionally excluded'),'free-form PDO driver detail is not logged');
        $this->same(false,str_contains($log,$temporaryPassword),'temporary password is never logged');
        $this->same(false,str_contains($log,$mfaSecret),'MFA secret is never logged');
        $this->same(false,str_contains($log,'Sensitive Person'),'the POST payload is never logged');

        $service=(string)file_get_contents(BASE_PATH.'/app/Services/UserAccountRequestService.php');
        foreach(['CHECK_USERNAME_COLLISION','REVALIDATE_ROLE_AND_SCOPE','LOAD_EXISTING_OFFICER','CREATE_OFFICER_AND_OFFICE_ASSIGNMENT','CREATE_SYSTEM_USER','CREATE_ROLE_AND_SCOPE_ASSIGNMENTS','AUDIT'] as $stage){
            $this->contains("\$stage='{$stage}'",$service,"request transaction identifies {$stage}");
        }
        $this->contains('if($exception instanceof DomainException',$service,'controlled business exceptions are not wrapped');

        echo "UserAccountRequestDiagnosticsTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function contains(string $needle,string $haystack,string $message):void
    {
        $this->same(true,str_contains($haystack,$needle),$message);
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new UserAccountRequestDiagnosticsTest())->run());
