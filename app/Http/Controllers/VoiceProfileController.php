<?php
namespace App\Http\Controllers;
use App\Models\{VoiceProfile,User};
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class VoiceProfileController extends Controller {
 public function index(Request $r){return view('voice.index',['profiles'=>VoiceProfile::where('user_id',$r->user()->id)->latest()->paginate(10)]);}
 private function owned(Request $r,string $id): VoiceProfile{return VoiceProfile::where('user_id',$r->user()->id)->findOrFail($id);}
 public function show(Request $r,string $id){$p=$this->owned($r,$id);return response()->json(['id'=>$p->id,'name'=>$p->name,'language'=>$p->language,'sample'=>$p->sample,'preferences'=>$p->preferences]);}
 public function edit(Request $r,string $id){return view('voice.edit',['profile'=>$this->owned($r,$id)]);}
 private function data(Request $r):array{return $r->validate(['name'=>'required|string|max:100','kind'=>['required',Rule::in(['personal','brand'])],'language'=>['required',Rule::in(['auto',...Locales::codes()])],'sample'=>'required|string|min:30|max:3000','preferences'=>'nullable|string|max:1000','storage_consent'=>'accepted']);}
 public function store(Request $r){
  $data=$this->data($r);unset($data['storage_consent']);
  DB::transaction(function()use($r,$data){User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();abort_if(VoiceProfile::where('user_id',$r->user()->id)->count()>=20,422,__('You can save up to 20 voice profiles.'));VoiceProfile::create($data+['user_id'=>$r->user()->id,'consented_at'=>now()]);});
  return redirect('/voice-profiles')->with('status',__('Voice profile saved.'));
 }
 public function update(Request $r,string $id){$profile=$this->owned($r,$id);$data=$this->data($r);unset($data['storage_consent']);$profile->update($data+['consented_at'=>now()]);return redirect('/voice-profiles')->with('status',__('Voice profile saved.'));}
 public function destroy(Request $r,string $id){$this->owned($r,$id)->delete();return redirect('/voice-profiles')->with('status',__('Voice profile deleted. Previously submitted jobs keep their normal processing retention.'));}
}
