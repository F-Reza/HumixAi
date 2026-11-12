<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\SitemapImportService;
use App\Models\{User,WebsiteCheck};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Validation\ValidationException;
class SitemapTest extends TestCase {
 protected function setUp():void{parent::setUp();DB::purge('sqlite');$this->artisan('migrate',['--force'=>true])->assertExitCode(0);config(['studio.url_import_enabled'=>true]);}
 private function user():User{return User::create(['name'=>'Publisher','email'=>uniqid().'@example.test','password'=>'test-password-123']);}
 private function service(string $xml,array $dns=[],?array $redirect=null):SitemapImportService{
  return new class($xml,$dns,$redirect) extends SitemapImportService{
   public int $fetches=0;
   public function __construct(private string $xml,private array $dns,private ?array $redirect){}
   protected function resolveAddresses(string $host,int $depth=0,array $seen=[]):array{return $this->dns[$host]??['93.184.215.14'];}
   protected function fetchPinned(string $url,string $host,int $port,string $ip):array{$this->fetches++;return $this->fetches===1 && $this->redirect?$this->redirect:['status'=>200,'content_type'=>'application/xml','body'=>$this->xml];}
  };
 }
 private function xml(array $urls,string $root='urlset'):string{$tag=$root==='urlset'?'url':'sitemap';return '<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('',array_map(fn($u)=>'<'.$tag.'><loc>'.htmlspecialchars($u,ENT_XML1).'</loc></'.$tag.'>',$urls)).'</'.$root.'>';}
 public function test_preview_deduplicates_and_skips_non_origin_or_unsafe_locations():void{
  $service=$this->service($this->xml(['https://example.com/a?one=1&two=2','https://example.com/a?one=1&two=2','https://other.com/a','http://example.com/b','http://127.0.0.1/private']));
  $result=$service->preview('https://example.com/sitemap.xml');$this->assertSame(['https://example.com/a?one=1&two=2'],$result['urls']);$this->assertSame(3,$result['skipped']);$this->assertSame(1,$service->fetches);Http::assertNothingSent();
 }
 public function test_index_is_not_recursively_fetched_and_preview_is_capped():void{
  $service=$this->service($this->xml(['https://example.com/child.xml'],'sitemapindex'));$this->assertSame('index',$service->preview('https://example.com/sitemap.xml')['kind']);$this->assertSame(1,$service->fetches);
  $result=$this->service($this->xml(array_map(fn($i)=>'https://example.com/page'.$i,range(1,205))))->preview('https://example.com/sitemap.xml');$this->assertCount(200,$result['urls']);$this->assertTrue($result['truncated']);
 }
 public function test_entities_malformed_xml_and_unrelated_roots_are_rejected():void{
  foreach(['<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///secret">]><urlset>&x;</urlset>','<urlset>','<html><body>Not a sitemap</body></html>']as$xml){try{$this->service($xml)->preview('https://example.com/sitemap.xml');$this->fail('Unsafe XML accepted');}catch(ValidationException $e){$this->assertArrayHasKey('url',$e->errors());}}
 }
 public function test_dns_and_redirect_safety_are_preserved():void{
  $service=$this->service($this->xml([]),['internal.example.com'=>['127.0.0.1']],['status'=>302,'location'=>'https://internal.example.com/map.xml']);
  try{$service->preview('https://example.com/map.xml');$this->fail('Private redirect accepted');}catch(ValidationException){$this->assertSame(1,$service->fetches);}
 }
 public function test_schedule_requires_current_owner_preview_and_explicit_consent():void{
  $u=$this->user();$this->actingAs($u);$service=$this->service($this->xml(['https://example.com/a','https://example.com/b']));$this->app->instance(SitemapImportService::class,$service);
  $this->post('/sitemap/preview',['url'=>'https://example.com/map.xml'])->assertRedirect('/sitemap');$this->get('/sitemap')->assertOk()->assertSee('https://example.com/a');$saved=session('sitemap_preview');
  $data=['nonce'=>$saved['nonce'],'selected'=>[0,1],'language'=>'en','frequency'=>'daily'];
  $this->postJson('/sitemap/schedule',$data)->assertStatus(422);$this->assertDatabaseCount('website_checks',0);
  $this->actingAs($this->user())->postJson('/sitemap/schedule',$data+['processing_consent'=>1])->assertStatus(422);
  $this->actingAs($u)->post('/sitemap/schedule',$data+['processing_consent'=>1])->assertRedirect('/website-checks');$this->assertDatabaseCount('website_checks',2);$this->assertSame(1,$service->fetches);Http::assertNothingSent();
  $this->postJson('/sitemap/schedule',$data+['processing_consent'=>1])->assertStatus(422);
 }
 public function test_expired_preview_invalid_selection_and_capacity_fail_atomically():void{
  $u=$this->user();$this->actingAs($u);$saved=['owner'=>$u->id,'nonce'=>'example-nonce','expires'=>time()+900,'preview'=>['kind'=>'pages','urls'=>['https://example.com/new']]];
  $data=['nonce'=>'example-nonce','selected'=>[1],'language'=>'en','frequency'=>'weekly','processing_consent'=>1];
  $this->withSession(['sitemap_preview'=>$saved])->postJson('/sitemap/schedule',$data)->assertStatus(422);
  $data['selected']=[0];$this->withSession(['sitemap_preview'=>array_replace($saved,['expires'=>time()-1])])->postJson('/sitemap/schedule',$data)->assertStatus(422);
  for($i=0;$i<10;$i++)WebsiteCheck::create(['user_id'=>$u->id,'url'=>'https://example.com/'.$i,'language'=>'en','frequency'=>'daily','next_run_at'=>now(),'consented_at'=>now()]);
  $this->withSession(['sitemap_preview'=>$saved])->postJson('/sitemap/schedule',$data)->assertStatus(422);$this->assertDatabaseCount('website_checks',10);
 }

 public function test_website_report_export_is_owner_only_and_does_not_average_scores():void{
  $u=$this->user();foreach([[$u,'https://example.com/mine'],[$this->user(),'https://example.com/other']]as[$owner,$url])WebsiteCheck::create(['user_id'=>$owner->id,'url'=>$url,'language'=>'en','frequency'=>'daily','next_run_at'=>now(),'consented_at'=>now()]);
  $this->actingAs($u)->get('/website-checks/export')->assertOk()->assertHeader('Content-Disposition','attachment; filename="humixai-website-reports.json"')->assertJsonPath('total_pages',1)->assertJsonPath('pages.0.url','https://example.com/mine')->assertDontSee('https://example.com/other');
 }
}
