<?php
namespace App\Http\Controllers;
use App\Models\{WebsiteCheck,User};
use App\Services\WebsiteCheckScheduler;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class WebsiteCheckController extends Controller{
 private function owned(Request $r,string $id):WebsiteCheck{return WebsiteCheck::where('user_id',$r->user()->id)->findOrFail($id);}
 public function index(Request $r){return view('website-checks.index',['checks'=>WebsiteCheck::where('user_id',$r->user()->id)->latest()->paginate(10)]);}

 public function export(Request $r){
  $checks=WebsiteCheck::where('user_id',$r->user()->id)->orderBy('created_at')->get();
  $rows=$checks->map(fn($check)=>['url'=>$check->url,'language'=>$check->language,'frequency'=>$check->frequency,'active'=>$check->active,'state'=>$check->state,'last_checked_at'=>$check->last_checked_at?->toIso8601String(),'next_run_at'=>$check->next_run_at->toIso8601String(),'report'=>$check->report]);
  return response()->json(['exported_at'=>now()->toIso8601String(),'total_pages'=>$checks->count(),'states'=>$checks->countBy('state'),'score_notice'=>__('Each page keeps its own provider probability. Page scores are not averaged into a website authorship score.'),'pages'=>$rows],200,['Content-Disposition'=>'attachment; filename="humixai-website-reports.json"']);
 }
 public function store(Request $r){
  $data=$r->validate(['url'=>'required|url:http,https|max:2048','language'=>['required',Rule::in(Locales::codes())],'frequency'=>['required',Rule::in(['daily','weekly'])],'processing_consent'=>'accepted']);unset($data['processing_consent']);
  DB::transaction(function()use($data,$r){User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();abort_if(WebsiteCheck::where('user_id',$r->user()->id)->count()>=10,422,__('You can save up to 10 scheduled pages.'));WebsiteCheck::create($data+['user_id'=>$r->user()->id,'consented_at'=>now(),'next_run_at'=>now()]);});
  return back()->with('status',__('Website check scheduled. The queue worker and scheduler must be running.'));
 }
 public function update(Request $r,string $id){$check=$this->owned($r,$id);$data=$r->validate(['active'=>'required|boolean']);if((bool)$data['active']===$check->active)return back();$inFlight=$check->run_token && $check->running_until?->isFuture() && in_array($check->state,['running','paused'],true);$check->update($data+['run_token'=>$inFlight?$check->run_token:null,'running_until'=>$inFlight?$check->running_until:null,'state'=>$data['active']?'scheduled':'paused','next_run_at'=>now()]);return back();}
 public function run(Request $r,string $id,WebsiteCheckScheduler $scheduler){abort_unless($scheduler->dispatch($this->owned($r,$id),true),409,__('This check is paused or already running.'));return back()->with('status',__('Website check queued. Refresh this page for the result.'));}
 public function destroy(Request $r,string $id){$this->owned($r,$id)->delete();return back()->with('status',__('Website check deleted.'));}
}
