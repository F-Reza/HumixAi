<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,WritingRecord};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class WritingRecordTest extends TestCase {
 protected function setUp():void{parent::setUp();DB::purge('sqlite');$this->artisan('migrate',['--force'=>true])->assertExitCode(0);}
 private function user():User{return User::create(['name'=>'Writer','email'=>uniqid().'@example.test','password'=>'password-12345']);}
 private function record():WritingRecord{$this->actingAs($this->user())->post('/writing-records',['title'=>'Draft','recording_text'=>'Private baseline','storage_consent'=>true])->assertRedirect();return WritingRecord::firstOrFail();}
 private function frame():array{return ['event_id'=>(string)Str::uuid(),'expected_sequence'=>1,'kind'=>'typing','recording_text'=>'New private draft'];}
 public function test_consent_and_history_required_and_private_text_not_flashed():void{
  $u=$this->user();$this->actingAs($u)->post('/writing-records',['title'=>'Draft','recording_text'=>'secret'])->assertSessionHasErrors('storage_consent')->assertSessionMissing('_old_input.recording_text');
  $u->forceFill(['history_enabled'=>false])->save();$this->postJson('/writing-records',['title'=>'Draft','storage_consent'=>true])->assertForbidden();
 }
 public function test_snapshots_are_encrypted_and_replay_is_explicit():void{
  $r=$this->record();$base='/writing-records/'.$r->id;
  $this->assertNotSame('Private baseline',DB::table('writing_frames')->value('text'));
  foreach(['/writing-records','/writing-records/new',$base]as$url)$this->get($url)->assertOk();
  $data=$this->frame();$this->postJson($base.'/frames',$data)->assertStatus(409);
  $this->patchJson($base.'/state',['active'=>true])->assertUnprocessable();
  $this->patchJson($base.'/state',['active'=>true,'storage_consent'=>true])->assertOk();
  $this->postJson($base.'/frames',$data)->assertOk()->assertJsonPath('sequence',1);
  $this->postJson($base.'/frames',$data)->assertOk();$this->assertDatabaseCount('writing_frames',2);
  $data['recording_text']='Conflict';$this->postJson($base.'/frames',$data)->assertStatus(409);
  $this->getJson($base.'/export')->assertOk()->assertJsonPath('frames.0.kind','initial')->assertJsonPath('frames.1.text','New private draft')->assertJsonMissingPath('human_percentage');
  $this->patchJson($base.'/state',['active'=>false])->assertOk();
  $this->postJson($base.'/frames',$this->frame())->assertStatus(409);
 }
 public function test_ownership_empty_text_limits_and_deletion():void{
  $r=$this->record();$owner=auth()->user();$base='/writing-records/'.$r->id;
  $this->actingAs($this->user());foreach([$base,$base.'/frames',$base.'/export']as$url)$this->getJson($url)->assertNotFound();
  $this->patchJson($base.'/state',['active'=>true,'storage_consent'=>true])->assertNotFound();$this->deleteJson($base)->assertNotFound();
  $this->actingAs($owner)->patchJson($base.'/state',['active'=>true,'storage_consent'=>true])->assertOk();
  $data=$this->frame();$data['recording_text']='';$this->postJson($base.'/frames',$data)->assertOk();
  $r->forceFill(['stored_bytes'=>4194304])->save();$data=$this->frame();$data['expected_sequence']=2;$this->postJson($base.'/frames',$data)->assertStatus(422);
  $this->delete($base)->assertRedirect('/writing-records');$this->assertDatabaseCount('writing_frames',0);
 }
 public function test_history_disabled_blocks_new_frames_and_account_deletion_cascades():void{
  $r=$this->record();$u=auth()->user();$u->forceFill(['history_enabled'=>false])->save();$this->postJson('/writing-records/'.$r->id.'/frames',$this->frame())->assertForbidden();$u->delete();$this->assertDatabaseCount('writing_frames',0);$this->assertDatabaseCount('writing_records',0);
 }
}
