<?php
namespace App\Http\Controllers;
use App\Models\{WritingRecord,WritingFrame,User,Document};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class WritingRecordController extends Controller{
 private function owned(Request $r,string $id):WritingRecord{return WritingRecord::where('user_id',$r->user()->id)->findOrFail($id);}
 private function allowStorage(Request $r):void{$u=User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();abort_unless($u->history_enabled && !$u->suspended_at,403,__('Enable document history before recording writing.'));}
 public function index(Request $r){return view('writing-records.index',['records'=>WritingRecord::where('user_id',$r->user()->id)->latest()->paginate(20)]);}
 public function create(Request $r){$document=$r->filled('document')?Document::where('user_id',$r->user()->id)->findOrFail($r->input('document')):null;return view('writing-records.new',compact('document'));}
 public function store(Request $r){
  $data=$r->validate(['title'=>'required|string|max:200','document_id'=>'nullable|uuid','recording_text'=>'nullable|string|max:60000','storage_consent'=>'accepted']);
  $record=DB::transaction(function()use($r,$data){
   $this->allowStorage($r);abort_if(WritingRecord::where('user_id',$r->user()->id)->count()>=20,422,__('You can keep up to 20 writing records. Delete an old record to start another.'));
   if(!empty($data['document_id']))Document::where('user_id',$r->user()->id)->findOrFail($data['document_id']);
   $text=$data['recording_text']??'';
   $record=WritingRecord::create(['user_id'=>$r->user()->id,'document_id'=>$data['document_id']??null,'title'=>$data['title'],'consented_at'=>now(),'stored_bytes'=>strlen($text),'frame_count'=>1]);
   $record->frames()->create(['event_id'=>(string)Str::uuid(),'sequence'=>0,'kind'=>'initial','text'=>$text,'text_hash'=>hash('sha256',$text),'characters'=>mb_strlen($text),'created_at'=>now()]);return $record;
  });return redirect('/writing-records/'.$record->id);
 }
 public function show(Request $r,string $id){$record=$this->owned($r,$id);$latest=$record->frames()->reorder()->orderByDesc('sequence')->first();return view('writing-records.show',compact('record','latest'));}
 public function state(Request $r,string $id){
  $data=$r->validate(['active'=>'required|boolean','storage_consent'=>$r->boolean('active')?'accepted':'nullable|boolean']);
  return DB::transaction(function()use($r,$id,$data){if($data['active'])$this->allowStorage($r);$record=$this->owned($r,$id);$record->forceFill(['active'=>$data['active']])->save();return response()->json(['active'=>$record->active]);});
 }
 public function append(Request $r,string $id){
  if($r->exists('recording_text') && $r->input('recording_text')===null)$r->merge(['recording_text'=>'']);
  $data=$r->validate(['event_id'=>'required|uuid','expected_sequence'=>'required|integer|min:1|max:300','kind'=>['required',Rule::in(['typing','paste','cut','drop','undo','redo','mixed','unknown'])],'recording_text'=>'present|string|max:60000']);
  return DB::transaction(function()use($r,$id,$data){
   $this->allowStorage($r);$record=WritingRecord::where('user_id',$r->user()->id)->whereKey($id)->lockForUpdate()->firstOrFail();
   $existing=$record->frames()->where('event_id',$data['event_id'])->first();$hash=hash('sha256',$data['recording_text']);
   if($existing){abort_unless(hash_equals($existing->text_hash,$hash) && $existing->kind===$data['kind'] && $existing->sequence===$data['expected_sequence'],409,__('This recording event conflicts with an earlier request.'));return response()->json(['sequence'=>$existing->sequence,'text_hash'=>$hash]);}
   abort_unless($record->active,409,__('Recording is paused.'));
   abort_unless($record->frame_count===$data['expected_sequence'],409,__('This record changed in another tab. Reload before recording again.'));
   abort_if($record->frame_count>=300 || $record->stored_bytes+strlen($data['recording_text'])>4194304,422,__('This record reached its 300-frame or 4 MiB limit. Stop and export it, then create another.'));
   $record->frames()->create(['event_id'=>$data['event_id'],'sequence'=>$record->frame_count,'kind'=>$data['kind'],'text'=>$data['recording_text'],'text_hash'=>$hash,'characters'=>mb_strlen($data['recording_text']),'created_at'=>now()]);
   $record->increment('frame_count');$record->increment('stored_bytes',strlen($data['recording_text']));
   return response()->json(['sequence'=>$data['expected_sequence'],'text_hash'=>$hash]);
  });
 }
 private function payload(WritingRecord $record):array{
  $frames=$record->frames()->get(['sequence','kind','text','text_hash','characters','created_at']);
  return ['title'=>$record->title,'consented_at'=>$record->consented_at->toIso8601String(),'frame_count'=>$frames->count(),'event_counts'=>$frames->countBy('kind'),'notice'=>__('This is a client-reported draft history, not proof of human authorship. The initial text predates recording; paste and input labels can be incomplete or modified. No human-written percentage is inferred.'),'frames'=>$frames];
 }
 public function frames(Request $r,string $id){return response()->json($this->payload($this->owned($r,$id)));}
 public function export(Request $r,string $id){return response()->json($this->payload($this->owned($r,$id)),200,['Content-Disposition'=>'attachment; filename="humixai-writing-record.json"']);}
 public function destroy(Request $r,string $id){$this->owned($r,$id)->delete();return redirect('/writing-records')->with('status',__('Writing record deleted.'));}
}
