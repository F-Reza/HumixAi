<?php
namespace App\Notifications;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
class VerifyAccountEmail extends VerifyEmail {
 protected function verificationUrl($user){return rtrim(config('app.url'),'/').URL::temporarySignedRoute('verification.verify',now()->addMinutes(60),['id'=>$user->id,'hash'=>sha1($user->getEmailForVerification())],absolute:false);}
 protected function buildMailMessage($url){return (new MailMessage)->subject(__('Verify your email'))->greeting(__('Hello!'))->line(__('Verify the email address on your HumixAi account.'))->action(__('Verify your email'),$url)->line(__('This link expires in :minutes minutes.',['minutes'=>60]))->line(__('If you did not request this, you can ignore this email.'))->salutation('HumixAi');}
}
