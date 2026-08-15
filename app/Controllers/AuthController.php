<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,CredentialService,Csrf,Database,LoginThrottleService,View};
use DomainException;

final class AuthController extends Controller
{
    public function login(): void
    {
        if (Auth::check()) redirect('/dashboard');
        $sessionNotice = match ((string)($_GET['session'] ?? '')) {
            'expired' => 'Your session has expired. Please sign in again.',
            'inactive' => 'Your account is no longer active. Please contact an administrator.',
            default => null,
        };
        View::render('auth/login', compact('sessionNotice'), 'layouts/auth');
    }

    public function authenticate(): void
    {
        Csrf::validate();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $result = (new LoginThrottleService(Database::pdo()))->authenticate(
            $username,
            $password,
            LoginThrottleService::clientIp($_SERVER)
        );
        if ($result === LoginThrottleService::SUCCESS) { if (!empty(Auth::user()['password_setup_required'])) redirect('/account/change-password'); redirect('/dashboard'); }
        $_SESSION['_flash'][] = ['type' => 'danger', 'message' => $result === LoginThrottleService::THROTTLED
            ? 'Too many login attempts. Please try again later.'
            : 'Invalid username or password.'];
        redirect('/login');
    }

    public function logout(): void
    {
        Csrf::validate();
        Auth::logout();
        redirect('/login');
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        $forced=!empty(Auth::user()['password_setup_required']);
        $this->render('auth/change_password',compact('forced'));
    }

    public function updatePassword(): void
    {
        Auth::requireLogin();Csrf::validate();
        try{
            CredentialService::changeOwnPassword(
                Database::pdo(),
                (string)Auth::user()['id'],
                (string)($_POST['current_password']??''),
                (string)($_POST['new_password']??''),
                (string)($_POST['new_password_confirmation']??'')
            );
            if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
            $this->flash('success','Password changed successfully');
            redirect('/dashboard');
        }catch(DomainException $e){
            $this->flash('danger',$e->getMessage());
            redirect('/account/change-password');
        }
    }
}
