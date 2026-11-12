<?php
namespace App\Http\Controllers;
use App\Services\SitemapImportService;
use App\Models\{User,WebsiteCheck};
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class SitemapController extends Controller {
 public function index(Request $r){
  $saved=$r->session()->get('sitemap_preview');
  $valid=is_array($saved) && ($saved['owner']??null)===$r->user()->id && ($saved['expires']??0)>=time();
  return view('website-checks.sitemap',['preview'=>$valid?$saved['preview']:null,'nonce'=>$valid?$saved['nonce']:null]);
 }
 public function preview(Request $r,SitemapImportService $service){
  $data=$r->validate(['url'=>'required|url:http,https|max:2048']);
  $preview=$service->preview($data['url']);
  // Server-held allowlist, account binding and expiry prevent edited hidden URL inputs.
  $nonce=bin2hex(random_bytes(24));
  $r->session()->put('sitemap_preview',['owner'=>$r->user()->id,'nonce'=>$nonce,'expires'=>time()+900,'preview'=>$preview]);
  return redirect('/sitemap');
 }
 public function store(Request $r){
  $data=$r->validate(['nonce'=>'required|string|max:100','selected'=>'required|array|min:1|max:10','selected.*'=>'required|integer|min:0|max:199','language'=>['required',Rule::in(Locales::codes())],'frequency'=>['required',Rule::in(['daily','weekly'])],'processing_consent'=>'accepted']);
  $saved=$r->session()->get('sitemap_preview');
  abort_unless(is_array($saved) && ($saved['owner']??null)===$r->user()->id && ($saved['expires']??0)>=time() && hash_equals($saved['nonce']??'',$data['nonce']) && ($saved['preview']['kind']??null)==='pages',422,__('The sitemap preview expired. Preview it again before scheduling.'));
  $urls=[];foreach(array_unique($data['selected']) as $index){abort_unless(isset($saved['preview']['urls'][$index]),422,__('Select pages from the current sitemap preview.'));$urls[]=$saved['preview']['urls'][$index];}
  DB::transaction(function()use($r,$data,$urls){
   User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
   $existing=WebsiteCheck::where('user_id',$r->user()->id)->get(['url']);$new=array_values(array_diff($urls,$existing->pluck('url')->all()));
   abort_if($existing->count()+count($new)>10,422,__('You can save up to 10 scheduled pages.'));
   foreach($new as $url)WebsiteCheck::create(['user_id'=>$r->user()->id,'url'=>$url,'language'=>$data['language'],'frequency'=>$data['frequency'],'consented_at'=>now(),'next_run_at'=>now()]);
  });
  $r->session()->forget('sitemap_preview');return redirect('/website-checks')->with('status',__('Selected pages scheduled. Existing schedules were kept unchanged.'));
 }
}
