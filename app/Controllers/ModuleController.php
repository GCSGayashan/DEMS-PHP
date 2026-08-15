<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;

final class ModuleController extends Controller
{
    private const MODULES = [
        'subject-management' => ['title'=>'Subject Management','description'=>'HR-controlled subject catalogue and subject assignment extension point.'],
        'file-management' => ['title'=>'File Management','description'=>'Enterprise file/document management module shell.'],
        'correspondence-management' => ['title'=>'Correspondence Management','description'=>'Incoming/outgoing correspondence module shell.'],
        'workflow-management' => ['title'=>'Workflow Management','description'=>'Cross-module workflow and approval monitoring shell.'],
        'reporting' => ['title'=>'Reporting and Dashboards','description'=>'Enterprise reports and dashboards module shell.'],
        'system-administration' => ['title'=>'System Administration','description'=>'System configuration and technical administration module shell.'],
    ];

    public function show(string $module): void
    {
        Auth::requireLogin();
        $cfg=self::MODULES[$module]??null;
        if(!$cfg){http_response_code(404);exit('Module not found.');}
        if($module==='system-administration'){
            $permissions=['user.view','role.manage','permission.view','user.view-security-history'];
            if(!array_filter($permissions,static fn(string $permission):bool=>Auth::can($permission))){
                http_response_code(403);
                View::render('partials/forbidden',['permission'=>'system administration access']);
                return;
            }
            $this->render('modules/system_administration',['cfg'=>$cfg]);
            return;
        }
        $this->render('modules/placeholder',['module'=>$module,'cfg'=>$cfg]);
    }
}
