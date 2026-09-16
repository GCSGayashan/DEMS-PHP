<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Csrf,DataTableRegistry,Database};
use App\Services\NotificationService;
use DomainException;

final class NotificationController extends Controller
{
    public function index():void
    {
        Auth::requireLogin();$view=(string)($_GET['view']??'action');if(!in_array($view,['action','unread','all','completed'],true))$view='action';
        $dataTable=DataTableRegistry::viewModel('notifications',['view'=>$view]);$this->render('notifications/index',compact('dataTable','view'));
    }
    public function markRead(string $id):void
    {
        Auth::requireLogin();Csrf::validate();try{(new NotificationService(Database::pdo()))->markRead($id,(string)Auth::user()['id']);$this->flash('success','Notification marked as read.');}catch(DomainException $e){http_response_code(404);$this->flash('danger',$e->getMessage());}redirect('/notifications');
    }
    public function open(string $id):void
    {
        Auth::requireLogin();$service=new NotificationService(Database::pdo());try{$notification=$service->forUser($id,(string)Auth::user()['id']);$service->markRead($id,(string)Auth::user()['id']);}catch(DomainException){http_response_code(404);$this->render('partials/not-found');return;}
        $context=Auth::activeContext();$requiredRole=$notification['required_role_assignment_id']?:null;$requiredScope=$notification['required_scope_assignment_id']?:null;
        if($requiredRole!==null&&($context===null||(string)$context['role_assignment_id']!==(string)$requiredRole||($requiredScope!==null&&(string)($context['scope_assignment_id']??'')!==(string)$requiredScope))){$this->flash('warning','This action requires a different working context. Select the role and office shown for that work, then reopen the notification.');redirect('/select-context');}
        $url=(string)($notification['action_url']??'');if($url===''||!str_starts_with($url,'/')||str_starts_with($url,'//'))redirect('/notifications');redirect($url);
    }
}
