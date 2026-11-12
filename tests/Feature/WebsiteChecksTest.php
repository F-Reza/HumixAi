<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,WebsiteCheck};
use App\Jobs\ProcessWebsiteCheck;
use App\Services\{WebsiteCheckScheduler,UrlImportService,UsageService};
use Illuminate\Support\Facades\{DB,Queue,Http};
class WebsiteChecksTest extends TestCase{
 protected function setUp():void{parent::setUp();DB::purge('sqlite');$this->artisan('migrate',['--force'=>true])->assertExitCode(0);}
 private function user():User{return User::create(['name'=>'Owner','email'=>uniqid().'@example.test','password'=>'test-password-123']);}
 private function check(User $user):WebsiteCheck{return WebsiteCheck::create(['user_id'=>$user->id,'url'=>'https://example.test/article','frequency'=>'daily','language'=>'en','consented_at'=>now(),'next_run_at'=>now()]);}
 public function test_schedule_requires_consent_and_is_tenant_scoped():void{
  $u=$this->user();$this->actingAs($u);$data=['url'=>'https://example.test/page','language'=>'en','frequency'=>'daily'];
  $this->postJson('/website-checks',$data)->assertStatus(422);$this->post('/website-checks',$data+['processing_consent'=>1])->assertRedirect();$check=WebsiteCheck::firstOrFail();
  $this->get('/website-checks')->assertOk()->assertSee('example.test/page');
  $this->actingAs($this->user())->patchJson('/website-checks/'.$check->id,['active'=>false])->assertNotFound();
  $this->delete('/website-checks/'.$check->id)->assertNotFound();
  $this->actingAs($u)->patch('/website-checks/'.$check->id,['active'=>false])->assertRedirect();$this->assertFalse($check->fresh()->active);
 }
 public function test_dispatch_claim_is_idempotent_and_pause_prevents_execution():void{
  Queue::fake();$check=$this->check($this->user());$scheduler=app(WebsiteCheckScheduler::class);
  $this->assertTrue($scheduler->dispatch($check));$this->assertFalse($scheduler->dispatch($check));Queue::assertPushed(ProcessWebsiteCheck::class,1);
  $check->refresh();$token=$check->run_token;$check->update(['active'=>false]);$this->mock(UrlImportService::class)->shouldNotReceive('preview');
  app()->call([new ProcessWebsiteCheck($check->id,$token),'handle']);Http::assertNothingSent();
 }
 public function test_worker_uses_actual_detector_and_retains_no_page_text():void{
  $u=$this->user();$check=$this->check($u);$check->update(['run_token'=>'run-1','state'=>'queued','running_until'=>now()->addMinutes(10)]);
  $text='Private page body should never be stored in the scheduled report.';
  $this->mock(UrlImportService::class)->shouldReceive('preview')->once()->with($check->url)->andReturn(['text'=>$text]);
  config(['services.gptzero.api_key'=>'test','services.gptzero.min_words'=>1]);Http::fake(['*'=>Http::response(['version'=>'test','documents'=>[['class_probabilities'=>['human'=>0.8,'mixed'=>0.1,'ai'=>0.1],'predicted_class'=>'human']]])]);
  $job=new ProcessWebsiteCheck($check->id,'run-1');app()->call([$job,'handle']);app()->call([$job,'handle']);$check->refresh();
  $this->assertSame('completed',$check->state);$this->assertEquals(10,$check->report['overall_score']);$this->assertStringNotContainsString($text,json_encode($check->report));
  $this->assertSame(hash('sha256',$text),$check->report['text_hash']);Http::assertSentCount(1);$this->assertGreaterThan(0,app(UsageService::class)->summary($u)['used_words']);
 }
 public function test_fetch_failure_is_explicit_without_fake_score_or_provider_request():void{
  $check=$this->check($this->user());$check->update(['run_token'=>'run-2','state'=>'queued','running_until'=>now()->addMinutes(10)]);
  $this->mock(UrlImportService::class)->shouldReceive('preview')->once()->andThrow(new \RuntimeException('internal network detail'));
  app()->call([new ProcessWebsiteCheck($check->id,'run-2'),'handle']);$check->refresh();
  $this->assertSame('failed',$check->state);$this->assertNull($check->report['overall_score']);$this->assertStringNotContainsString('internal network detail',json_encode($check->report));Http::assertNothingSent();
 }

 public function test_failed_worker_releases_reserved_words():void{
  $u=$this->user();$reservation=app(UsageService::class)->reserve($u,40,'detect');$check=$this->check($u);
  $check->update(['run_token'=>'run-3','state'=>'running','running_until'=>now()->addMinutes(10),'reservation_id'=>$reservation['reservation_id']]);
  (new ProcessWebsiteCheck($check->id,'run-3'))->failed(new \RuntimeException('timeout'));
  $this->assertSame(0,app(UsageService::class)->summary($u)['reserved_words']);$this->assertNull($check->fresh()->reservation_id);
 }
 public function test_pausing_a_running_check_does_not_enable_overlapping_dispatch():void{
  $u=$this->user();$check=$this->check($u);$check->update(['run_token'=>'in-flight','state'=>'running','running_until'=>now()->addMinutes(10)]);$this->actingAs($u);
  $this->patch('/website-checks/'.$check->id,['active'=>false])->assertRedirect();
  $this->patch('/website-checks/'.$check->id,['active'=>true])->assertRedirect();
  Queue::fake();$this->assertFalse(app(WebsiteCheckScheduler::class)->dispatch($check->fresh(),true));Queue::assertNothingPushed();
 }
}
