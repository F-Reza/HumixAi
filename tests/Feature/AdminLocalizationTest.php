<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,StudioJob};
use App\Services\{UsageService,TextAnalysisService,AiDetectorService};
use Illuminate\Support\Facades\{DB,Http};
class AdminLocalizationTest extends TestCase {
    protected function setUp(): void {parent::setUp();DB::purge('sqlite');$this->artisan('migrate',['--force'=>true])->assertExitCode(0);}
    private function user(string $role='user'): User {$u=User::create(['name'=>'Writer','email'=>uniqid().'@example.test','password'=>'test-password-123']);$u->forceFill(['role'=>$role])->save();return $u;}
    public function test_admin_endpoints_deny_guests_regular_users_and_api_tokens(): void {
        $this->getJson('/admin/api/dashboard')->assertUnauthorized();
        $u=$this->user();$this->actingAs($u)->getJson('/admin/api/dashboard')->assertForbidden();
        $this->patchJson('/admin/api/settings',['settings'=>['allow_registration'=>false]])->assertForbidden();
    }
    public function test_admin_can_review_metadata_but_cannot_change_secrets_or_roles(): void {
        $admin=$this->user('admin');$user=$this->user();$this->actingAs($admin);
        $this->get('/admin')->assertOk()->assertSee('System Admin');
        $this->getJson('/admin/api/dashboard')->assertOk()->assertJsonPath('users',2);
        $this->patchJson('/admin/api/users/'.$user->id,['role'=>'admin','current_password'=>'test-password-123'])->assertForbidden();
        $this->putJson('/admin/api/providers/gptzero',['api_key'=>'secret-test-123','current_password'=>'test-password-123'])->assertForbidden();
        $this->patchJson('/admin/api/users/'.$user->id,['quota_override'=>500,'current_password'=>'test-password-123'])->assertOk();
        $this->assertSame(500,app(UsageService::class)->dailyLimit($user->fresh()));
    }
    public function test_secret_storage_is_encrypted_masked_and_audited_without_value(): void {
        $this->actingAs($this->user('super_admin'));
        $this->putJson('/admin/api/providers/gptzero',['api_key'=>'sensitive-key-123','current_password'=>'wrong'])->assertStatus(422);
        $this->putJson('/admin/api/providers/gptzero',['api_key'=>'sensitive-key-123','current_password'=>'test-password-123'])->assertOk()->assertDontSee('sensitive-key-123');
        $this->assertNotSame('sensitive-key-123',DB::table('system_settings')->where('key','provider.gptzero')->value('value'));
        $this->getJson('/admin/api/providers')->assertOk()->assertDontSee('sensitive-key-123');
        $this->getJson('/admin/api/audit')->assertOk()->assertDontSee('sensitive-key-123')->assertSee('provider.update');
        $this->assertSame('sensitive-key-123',config('services.gptzero.api_key'));
        $this->putJson('/admin/api/providers/gptzero',['clear'=>true,'current_password'=>'test-password-123'])->assertOk();
        $this->assertSame('',config('services.gptzero.api_key'));
    }
    public function test_settings_are_bounded_applied_and_audited(): void {
        $this->actingAs($this->user('super_admin'));
        $this->patchJson('/admin/api/settings',['settings'=>['max_characters'=>999999],'current_password'=>'test-password-123'])->assertStatus(422);
        $this->patchJson('/admin/api/settings',['settings'=>['base_url'=>'http://localhost'],'current_password'=>'test-password-123'])->assertStatus(422);
        $this->patchJson('/admin/api/settings',['settings'=>['allow_registration'=>false,'daily_word_limit'=>777],'current_password'=>'test-password-123'])->assertOk();
        $this->postJson('/register',[])->assertForbidden();
        $this->assertSame(777,app(UsageService::class)->dailyLimit($this->user()));
    }
    public function test_self_demotion_and_last_admin_deletion_are_blocked(): void {
        $admin=$this->user('super_admin');$this->actingAs($admin);
        $this->patchJson('/admin/api/users/'.$admin->id,['role'=>'user','current_password'=>'test-password-123'])->assertStatus(422);
        $this->patchJson('/admin/api/users/'.$admin->id,['suspended'=>true,'current_password'=>'test-password-123'])->assertStatus(422);
        $this->deleteJson('/account',['password'=>'test-password-123'])->assertStatus(422);
    }
    public function test_suspension_blocks_existing_session_and_queued_work(): void {
        $admin=$this->user('super_admin');$user=$this->user();
        $reservation=app(UsageService::class)->reserve($user,100,'detect');
        $job=StudioJob::create(['user_id'=>$user->id,'reservation_id'=>$reservation['reservation_id'],'action'=>'detect','status'=>'queued','payload'=>['text'=>str_repeat('Private writing ',100)]]);
        $this->actingAs($admin)->patchJson('/admin/api/users/'.$user->id,['suspended'=>true,'current_password'=>'test-password-123'])->assertOk();
        $this->actingAs($user->fresh())->getJson('/documents')->assertForbidden();
        app()->call([new \App\Jobs\ProcessStudioJob($job->id),'handle']);
        Http::assertNothingSent();$this->assertSame('failed',$job->fresh()->status);$this->assertSame([],$job->fresh()->payload);
        $this->assertSame(0,app(UsageService::class)->summary($user)['reserved_words']);
    }
    public function test_cancel_releases_reservation_and_hides_drafts(): void {
        $user=$this->user();$reservation=app(UsageService::class)->reserve($user,100,'detect');
        $job=StudioJob::create(['user_id'=>$user->id,'reservation_id'=>$reservation['reservation_id'],'action'=>'detect','status'=>'queued','payload'=>['text'=>'PRIVATE ORIGINAL DRAFT']]);
        $this->actingAs($this->user('admin'))->getJson('/admin/api/jobs')->assertOk()->assertDontSee('PRIVATE ORIGINAL DRAFT');
        $this->postJson('/admin/api/jobs/'.$job->id.'/cancel',[])->assertOk();
        $this->postJson('/admin/api/jobs/'.$job->id.'/cancel',[])->assertStatus(409);
        $this->assertSame([],$job->fresh()->payload);$this->assertSame(0,app(UsageService::class)->summary($user)['reserved_words']);
    }
    public function test_locale_is_persisted_and_all_six_pages_render_with_correct_direction(): void {
        $u=$this->user();$this->actingAs($u);
        foreach(['en','bn','hi','ur','ar','ne'] as $locale){
            $this->patchJson('/locale',['locale'=>$locale])->assertOk();
            $this->assertSame($locale,$u->fresh()->locale);
            $this->get('/')->assertOk()->assertSee('lang="'.$locale.'"',false)->assertSee('dir="'.(in_array($locale,['ur','ar'])?'rtl':'ltr').'"',false);
        }
        $this->patchJson('/locale',['locale'=>'xx'])->assertStatus(422);
    }
    public function test_script_ambiguity_and_unicode_spans_are_preserved(): void {
        $service=new TextAnalysisService();
        $texts=['en'=>'This is a sentence. Here is another.','bn'=>'আমি বাংলা লিখি। তুমি কেমন আছ?','hi'=>'यह पहला वाक्य है। यह दूसरा वाक्य है।','ne'=>'यो पहिलो वाक्य हो। यो दोस्रो वाक्य हो।','ur'=>'یہ پہلا جملہ ہے۔ یہ دوسرا جملہ ہے۔','ar'=>'هذه جملة أولى. هل هذه جملة ثانية؟'];
        foreach($texts as $language=>$text){$result=$service->analyze($text,$language);$this->assertSame($language,$result['language']);$this->assertSame(2,$result['sentence_count']);foreach($service->sentences($text)as$s)$this->assertSame($s['text'],mb_substr($text,$s['start'],$s['end']-$s['start']));}
        $this->assertSame('und',$service->language($texts['hi']));$this->assertSame('und',$service->language($texts['ar']));
    }
    public function test_six_languages_use_real_provider_probabilities_without_translation(): void {
        config(['services.gptzero.api_key'=>'fake','services.gptzero.min_words'=>1]);
        Http::fake(['*'=>Http::response(['version'=>'test','documents'=>[['class_probabilities'=>['human'=>0.7,'mixed'=>0.2,'ai'=>0.1],'predicted_class'=>'human']]])]);
        foreach(['en'=>'This is original writing.','bn'=>'এটি আমার নিজের লেখা।','hi'=>'यह मेरी अपनी रचना है।','ne'=>'यो मेरो आफ्नै लेख हो।','ur'=>'یہ میری اپنی تحریر ہے۔','ar'=>'هذه كتابتي الأصلية.']as$language=>$text){
            $result=app(AiDetectorService::class)->detect($text,$language);$this->assertSame('completed',$result['status']);$this->assertSame(10.0,$result['overall_score']);$this->assertFalse($result['language_evaluated']);Http::assertSent(fn($r)=>$r['document']===$text);
        }
    }

    public function test_usage_adjustments_preserve_reservations_and_require_reason(): void {
        $user=$this->user();$usage=app(UsageService::class);$reservation=$usage->reserve($user,100,'detect');$usage->settle($reservation['reservation_id'],80);
        $usage->reserve($user,50,'detect');$this->actingAs($this->user('super_admin'));
        $path='/admin/api/users/'.$user->id.'/usage-adjustments';
        $data=['current_password'=>'test-password-123','date'=>now(config('studio.usage_timezone'))->toDateString(),'reason'=>'Correct overcount','words_delta'=>-30];
        $this->postJson($path,$data)->assertOk();$this->assertSame(50,$usage->summary($user)['used_words']);$this->assertSame(50,$usage->summary($user)['reserved_words']);
        $this->postJson($path,array_replace($data,['words_delta'=>-999]))->assertStatus(422);
        $this->getJson('/admin/api/audit')->assertDontSee('Correct overcount')->assertSee('usage.adjust');
    }
    public function test_localized_validation_and_no_implicit_administrator_registration(): void {
        $this->withSession(['locale'=>'bn'])->postJson('/register',['role'=>'super_admin'])->assertStatus(422);
        $this->assertNotSame('The Name field is required.',__('validation.required',['attribute'=>__('Name')], 'bn'));
        $this->assertSame(0,User::where('role','super_admin')->count());
        $this->artisan('humix:admin',['email'=>'missing@example.test'])->assertExitCode(1);
    }

    public function test_cli_creates_admin_with_hashed_password_and_redacted_audit(): void {
        $this->artisan('humix:admin',['email'=>'ADMIN@example.test','--create'=>true])
            ->expectsQuestion('Administrator name','System Operator')
            ->expectsQuestion('Password (at least 10 characters)','strong-test-password')
            ->expectsQuestion('Confirm password','strong-test-password')->assertExitCode(0);
        $user=User::where('email','admin@example.test')->firstOrFail();
        $this->assertSame('super_admin',$user->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('strong-test-password',$user->password));
        $audit=DB::table('admin_audits')->where('action','admin.create_cli')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('strong-test-password',json_encode($audit));
    }
    public function test_cli_rejects_mismatched_password_without_creating_account(): void {
        $this->artisan('humix:admin',['email'=>'new@example.test','--create'=>true])
            ->expectsQuestion('Administrator name','System Operator')
            ->expectsQuestion('Password (at least 10 characters)','strong-test-password')
            ->expectsQuestion('Confirm password','different-password')->assertExitCode(1);
        $this->assertDatabaseMissing('users',['email'=>'new@example.test']);
    }
    public function test_create_option_does_not_reset_existing_password_or_reactivate_suspended_user(): void {
        $user=$this->user();$hash=$user->password;
        $this->artisan('humix:admin',['email'=>$user->email,'--create'=>true])->assertExitCode(0);
        $this->assertSame($hash,$user->fresh()->password);
        $user->forceFill(['suspended_at'=>now()])->save();
        $this->artisan('humix:admin',['email'=>$user->email,'--create'=>true])->assertExitCode(1);
        $this->assertNotNull($user->fresh()->suspended_at);
    }

    public function test_logout_clears_session_and_allows_suspended_and_guest_users(): void {
        $user=$this->user('super_admin');
        $this->actingAs($user)->withSession(['private_marker'=>'secret'])->postJson('/logout')
            ->assertOk()->assertJsonPath('data.logged_out',true)->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->getJson('/admin/api/dashboard')->assertUnauthorized();
        $this->postJson('/logout')->assertOk();
        $user->forceFill(['suspended_at'=>now()])->save();
        $this->actingAs($user)->postJson('/auth/logout')->assertOk();
        $this->assertGuest();
    }
    public function test_native_logout_form_redirects_to_login(): void {
        $this->actingAs($this->user())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
