<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Password,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Auth\Events\{PasswordReset,Verified};
use Illuminate\Validation\ValidationException;
class AccountSecurityController extends Controller {
 public static function emailEnabled(): bool {return config('mail.account_enabled') && config('mail.default')==='smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address'));}
 private function requireMail(): void {abort_unless(self::emailEnabled(),503,__('Account email is not configured. Contact the administrator.'));}
 public function forgot(Request $r) {
  $this->requireMail();$r->merge(['email'=>mb_strtolower(trim((string)$r->input('email')))]);
  $data=$r->validate(['email'=>'required|email:rfc|max:254']);
  $key='recovery:'.hash('sha256',$data['email']);
  if (!RateLimiter::tooManyAttempts($key,3)) {
   RateLimiter::hit($key,3600);
   $user=User::where('email',$data['email'])->whereNull('suspended_at')->first();
   if($user){try {Password::sendResetLink(['email'=>$user->email]);}catch(\Throwable){/* No email existence or transport details in the response. */}}
  }
  return back()->with('status',__('If an eligible account exists, a password reset link will be sent.'));
 }
 public function reset(Request $r) {
  $data=$r->validate(['token'=>'required|string|max:256','email'=>'required|email:rfc|max:254','password'=>'required|string|min:10|max:128|confirmed']);
  $status=Password::reset($data,function(User $user,string $password){
   abort_if($user->suspended_at,403,__('This account is suspended.'));
   DB::transaction(function()use($user,$password){
    $user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();
    DB::table('api_tokens')->where('user_id',$user->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
    DB::table('document_shares')->where('user_id',$user->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
   });event(new PasswordReset($user));
  });
  if($status!==Password::PASSWORD_RESET)throw ValidationException::withMessages(['email'=>__($status)]);
  Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();
  return redirect()->route('login')->with('status',__('Your password has been reset. Sign in with your new password.'));
 }
 public function sendVerification(Request $r) {
  $this->requireMail();if($r->user()->hasVerifiedEmail())return back()->with('status',__('Your email is already verified.'));
  try {$r->user()->sendEmailVerificationNotification();}catch(\Throwable){throw ValidationException::withMessages(['email'=>__('Verification email could not be sent. Please try again later.')]);}
  return back()->with('status',__('A verification link has been sent to your email.'));
 }
 public function verify(Request $r,string $id,string $hash) {
  abort_unless((string)$r->user()->id===$id && hash_equals(sha1($r->user()->getEmailForVerification()),$hash),403);
  if(!$r->user()->hasVerifiedEmail() && $r->user()->markEmailAsVerified())event(new Verified($r->user()));
  return redirect('/account/security')->with('status',__('Your email is verified.'));
 }
}
