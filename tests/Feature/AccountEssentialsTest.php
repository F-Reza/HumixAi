<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,VoiceProfile};
use App\Notifications\{ResetAccountPassword,VerifyAccountEmail};
use Illuminate\Support\Facades\{DB,Notification,Password,Hash,URL};
class AccountEssentialsTest extends TestCase {
 protected function setUp():void{parent::setUp();DB::purge('sqlite');$this->artisan('migrate',['--force'=>true])->assertExitCode(0);Notification::fake();config(['mail.account_enabled'=>true,'mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'hello@example.test']);}
 private function user():User{return User::create(['name'=>'Writer','email'=>uniqid().'@example.test','password'=>'old-password-123']);}
 public function test_recovery_is_generic_and_never_returns_a_reset_token():void{
  $user=$this->user();$this->from('/forgot-password')->post('/forgot-password',['email'=>$user->email])->assertRedirect('/forgot-password')->assertSessionHas('status');
  Notification::assertSentTo($user,ResetAccountPassword::class);$this->assertDatabaseHas('password_reset_tokens',['email'=>$user->email]);
  $this->from('/forgot-password')->post('/forgot-password',['email'=>'unknown@example.test'])->assertRedirect('/forgot-password')->assertSessionHas('status');
  Notification::assertCount(1);
 }
 public function test_disabled_mail_does_not_send_or_log_reset_tokens():void{
  config(['mail.account_enabled'=>false]);$user=$this->user();$this->post('/forgot-password',['email'=>$user->email])->assertStatus(503);Notification::assertNothingSent();$this->assertDatabaseCount('password_reset_tokens',0);
 }
 public function test_reset_changes_password_consumes_token_and_revokes_access():void{
  $user=$this->user();$token=Password::createToken($user);
  $this->post('/reset-password',['email'=>$user->email,'token'=>$token,'password'=>'new-password-123','password_confirmation'=>'new-password-123'])->assertRedirect('/login');
  $this->assertTrue(Hash::check('new-password-123',$user->fresh()->password));$this->assertDatabaseMissing('password_reset_tokens',['email'=>$user->email]);
  $this->postJson('/reset-password',['email'=>$user->email,'token'=>$token,'password'=>'other-password-123','password_confirmation'=>'other-password-123'])->assertStatus(422);
 }
 public function test_password_change_invalidates_preexisting_session():void{
  $user=$this->user();$old=$user->password;$user->forceFill(['password'=>'changed-password-123'])->save();
  $this->actingAs($user)->withSession(['password_hash_web'=>$old])->getJson('/documents')->assertUnauthorized();$this->assertGuest();
 }
 public function test_verification_requires_matching_user_and_valid_signature():void{
  $user=$this->user();$url=URL::temporarySignedRoute('verification.verify',now()->addMinutes(60),['id'=>$user->id,'hash'=>sha1($user->email)],absolute:false);
  $this->actingAs($this->user())->get($url)->assertForbidden();
  $this->actingAs($user)->get($url.'&tampered=1')->assertForbidden();
  $this->get($url)->assertRedirect('/account/security');$this->assertNotNull($user->fresh()->email_verified_at);
 }
 public function test_mail_links_use_configured_origin():void{
  $u=$this->user();config(['app.url'=>'https://humix.example']);$this->assertStringStartsWith('https://humix.example/',(new ResetAccountPassword('test-token'))->toMail($u)->actionUrl);
  $this->assertStringStartsWith('https://humix.example/',(new VerifyAccountEmail)->toMail($u)->actionUrl);
 }
 public function test_voice_requires_consent_and_encrypts_samples():void{
  $u=$this->user();$this->actingAs($u);$data=['name'=>'Brand','kind'=>'brand','language'=>'bn','sample'=>'This is my private writing style example.','preferences'=>'Clear and concise.'];
  $this->postJson('/voice-profiles',$data)->assertStatus(422);
  $this->post('/voice-profiles',$data+['storage_consent'=>1])->assertRedirect('/voice-profiles');$p=VoiceProfile::firstOrFail();
  $this->assertNotSame($data['sample'],DB::table('voice_profiles')->value('sample'));$this->assertSame($data['sample'],$p->sample);$this->assertNotNull($p->consented_at);
  $this->getJson('/voice-profiles/'.$p->id)->assertJsonPath('sample',$data['sample']);
  $this->actingAs($this->user())->getJson('/voice-profiles/'.$p->id)->assertNotFound();$this->delete('/voice-profiles/'.$p->id)->assertNotFound();
  $this->actingAs($u)->delete('/voice-profiles/'.$p->id)->assertRedirect('/voice-profiles');$this->assertDatabaseMissing('voice_profiles',['id'=>$p->id]);
 }
 public function test_voice_storage_is_limited_and_owner_deletion_cascades():void{
  $u=$this->user();for($i=0;$i<20;$i++)VoiceProfile::create(['user_id'=>$u->id,'name'=>'Voice','sample'=>'A private sample','consented_at'=>now()]);
  $this->actingAs($u)->postJson('/voice-profiles',['name'=>'Extra','kind'=>'personal','language'=>'en','sample'=>str_repeat('sample ',8),'storage_consent'=>1])->assertStatus(422);
  $u->delete();$this->assertDatabaseCount('voice_profiles',0);
 }
 public function test_new_account_pages_render_without_provider_requests():void{
  $this->get('/forgot-password')->assertOk();$this->get('/reset-password/example?email=writer@example.test')->assertOk();$this->actingAs($this->user());
  foreach(['/voice-profiles','/voice-profiles/new','/account/security']as$url)$this->get($url)->assertOk();
 }

 public function test_reset_link_expiry_and_account_deletion_remove_recovery_access():void{
  $u=$this->user();$token=Password::createToken($u);$this->travel(61)->minutes();
  $this->postJson('/reset-password',['email'=>$u->email,'token'=>$token,'password'=>'new-password-123','password_confirmation'=>'new-password-123'])->assertStatus(422);
  $this->travelBack();$this->actingAs($u)->deleteJson('/account',['password'=>'old-password-123'])->assertOk();
  $this->assertDatabaseMissing('password_reset_tokens',['email'=>$u->email]);
 }
 public function test_missing_storage_consent_does_not_flash_a_private_voice_sample():void{
  $u=$this->user();$this->actingAs($u)->from('/voice-profiles/new')->post('/voice-profiles',['name'=>'Private','kind'=>'personal','language'=>'en','sample'=>'A private writing sample that must not be flashed.','preferences'=>'Private preferences'])->assertRedirect('/voice-profiles/new')->assertSessionMissing('_old_input.sample')->assertSessionMissing('_old_input.preferences');
 }
}
