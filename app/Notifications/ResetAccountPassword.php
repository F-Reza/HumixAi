<?php
namespace App\Notifications;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
class ResetAccountPassword extends ResetPassword {
 protected function resetUrl($user){return rtrim(config('app.url'),'/').'/reset-password/'.rawurlencode($this->token).'?'.http_build_query(['email'=>$user->email]);}
 protected function buildMailMessage($url){return (new MailMessage)->subject(__('Reset password'))->greeting(__('Hello!'))->line(__('A password reset was requested for your HumixAi account.'))->action(__('Reset password'),$url)->line(__('This link expires in :minutes minutes.',['minutes'=>config('auth.passwords.users.expire',60)]))->line(__('If you did not request this, you can ignore this email.'))->salutation('HumixAi');}
}
