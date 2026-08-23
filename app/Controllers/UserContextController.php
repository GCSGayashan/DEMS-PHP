<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Audit,Auth,Csrf,Database,View};
use App\Services\UserContextService;
use DomainException;

final class UserContextController
{
    public function select():void
    {
        Auth::requireLogin(false);
        $contexts=Auth::availableContexts();$activeContext=Auth::activeContext();$user=Auth::user();
        if(count($contexts)===1&&$activeContext!==null){redirect('/dashboard');}
        View::render('auth/select_context',compact('contexts','activeContext','user'),'layouts/auth');
    }

    public function activate():void
    {
        Auth::requireLogin(false);Csrf::validate();$user=Auth::user();
        try{
            $scopeId=isset($_POST['scope_assignment_id'])&&$_POST['scope_assignment_id']!==''?(string)$_POST['scope_assignment_id']:null;
            $context=(new UserContextService(Database::pdo()))->select(
                (string)$user['id'],(string)($_POST['role_assignment_id']??''),$scopeId
            );
            Auth::forgetRequestCache();
            Audit::record('user.context.select','USER_ROLE',(string)$context['role_assignment_id'],[
                'scope_assignment_id'=>$context['scope_assignment_id'],
                'role_code'=>$context['role_code'],
                'location_id'=>$context['location_id'],
            ]);
            redirect('/dashboard');
        }catch(DomainException $exception){
            $_SESSION['_flash'][]=['type'=>'danger','message'=>$exception->getMessage()];
            Auth::forgetRequestCache();redirect('/select-context');
        }
    }
}
