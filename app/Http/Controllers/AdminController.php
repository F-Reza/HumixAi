<?php
namespace App\Http\Controllers;
use App\Models\{User,StudioJob};
use App\Services\Admin\SystemSettingsService;
use App\Services\UsageService;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,Crypt,Artisan};
use Illuminate\Validation\Rule;

class AdminController extends Controller {
    private function confirm(Request $r, bool $super = false): void {
        abort_if($super && $r->user()->role !== 'super_admin',403,__('Super administrator access required.'));
        $r->validate(['current_password'=>'required|string|max:128']);
        abort_unless(Hash::check($r->input('current_password'),$r->user()->password),422,__('The password is incorrect.'));
    }
    private function audit(Request $r,string $action,string $target='',array $meta=[]): void { app(SystemSettingsService::class)->audit($r->user()->id,$action,$target,$meta); }
    public function dashboard() {
        return response()->json(['users'=>User::count(),'suspended'=>User::whereNotNull('suspended_at')->count(),'documents'=>DB::table('documents')->count(),'teams'=>DB::table('teams')->count(),
            'jobs'=>StudioJob::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status'),
            'usage'=>DB::table('usage_days')->where('date',now(config('studio.usage_timezone'))->toDateString())->selectRaw('coalesce(sum(used_words),0) as used_words, coalesce(sum(reserved_words),0) as reserved_words')->first(),
            'queue_connection'=>config('studio.queue_connection'),'failed_queue_jobs'=>DB::table('failed_jobs')->count()]);
    }
    public function users(Request $r) {
        $r->validate(['search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1']);
        return User::query()->when($r->filled('search'),fn($q)=>$q->where(fn($q)=>$q->where('email','like','%'.$r->input('search').'%')->orWhere('name','like','%'.$r->input('search').'%')))->latest('id')->paginate(20);
    }
    public function updateUser(Request $r, User $user) {
        $this->confirm($r);
        $data=$r->validate(['name'=>'sometimes|required|string|max:100','locale'=>['sometimes',Rule::in(Locales::codes())],'quota_override'=>'sometimes|nullable|integer|min:0|max:1000000','suspended'=>'sometimes|boolean','role'=>['sometimes',Rule::in(['user','admin','super_admin'])]]);
        return DB::transaction(function()use($r,$user,$data){
            // Serialize changes to privileged accounts, including last-super-admin checks.
            User::where('role','super_admin')->orderBy('id')->lockForUpdate()->get();
            $user=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->role !== 'user' || isset($data['role'])) abort_unless($r->user()->role==='super_admin',403,__('Super administrator access required.'));
            if (($data['suspended']??false) || (isset($data['role']) && $data['role']!==$user->role)) {
                abort_if($user->id===$r->user()->id,422,__('You cannot suspend or demote your own account.'));
                self::guardLastAdmin($user);
            }
            if (array_key_exists('suspended',$data)) { $data['suspended_at']=$data['suspended']?now():null; unset($data['suspended']); }
            $user->forceFill($data)->save();
            if ($user->suspended_at) { $this->revoke($user); }
            $this->audit($r,'user.update',(string)$user->id,['fields'=>array_keys($data)]);
            return response()->json(['user'=>$user]);
        });
    }
    public static function guardLastAdmin(User $user): void {
        abort_if($user->role==='super_admin' && !$user->suspended_at && User::where('role','super_admin')->whereNull('suspended_at')->where('id','!=',$user->id)->count()===0,422,__('The last active super administrator cannot be removed.'));
    }
    private function revoke(User $user): void {
        DB::table('api_tokens')->where('user_id',$user->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
        DB::table('document_shares')->where('user_id',$user->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
    }
    public function revokeAccess(Request $r,User $user) {
        $this->confirm($r,$user->role!=='user');
        DB::transaction(function()use($r,$user){$this->revoke($user);$this->audit($r,'user.revoke_access',(string)$user->id);});
        return response()->json(['success'=>true]);
    }
    public function jobs(Request $r) {
        $r->validate(['status'=>['nullable',Rule::in(['queued','running','completed','partial','failed','cancelled'])]]);
        return StudioJob::select(['id','user_id','action','status','stage','created_at','completed_at'])->when($r->filled('status'),fn($q)=>$q->where('status',$r->input('status')))->latest()->paginate(20);
    }
    public function adjustUsage(Request $r, User $user) {
        $this->confirm($r, $user->role !== 'user');
        $data = $r->validate(['date'=>'required|date_format:Y-m-d', 'words_delta'=>'required|integer|between:-1000000,1000000', 'reason'=>'required|string|min:5|max:500']);
        return DB::transaction(function () use ($r, $user, $data) {
            $day = DB::table('usage_days')->where('user_id',$user->id)->where('date',$data['date'])->lockForUpdate()->first();
            abort_unless($day,422,__('No usage record exists for this date.'));
            $used = $day->used_words + $data['words_delta'];
            abort_if($used < 0 || $used > 1000000,422,__('The usage adjustment is outside the allowed range.'));
            DB::table('usage_days')->where('id',$day->id)->update(['used_words'=>$used, 'updated_at'=>now()]);
            $this->audit($r,'usage.adjust',(string)$user->id,['date'=>$data['date'],'delta'=>$data['words_delta'],'reason_hash'=>hash('sha256',$data['reason'])]);
            return response()->json(['success'=>true]);
        });
    }
    public function cancel(Request $r,StudioJob $job) {
        return DB::transaction(function()use($r,$job){
            // Same lock order as the queue worker: reservation, then job.
            DB::table('usage_reservations')->where('id',$job->reservation_id)->lockForUpdate()->first();
            $changed=StudioJob::whereKey($job->id)->where('status','queued')->update(['status'=>'failed','stage'=>'cancelled','payload'=>'[]','completed_at'=>now()]);
            abort_unless($changed,409,__('Only queued jobs can be cancelled.'));
            if($job->reservation_id) app(UsageService::class)->release($job->reservation_id);
            $this->audit($r,'job.cancel',$job->id);
            return response()->json(['success'=>true]);
        });
    }
    public function settings() {
        $settings=[];foreach(SystemSettingsService::SCHEMA as $key=>$rules)$settings[$key]=config('studio.'.$key);
        return response()->json(['settings'=>$settings,'schema'=>SystemSettingsService::SCHEMA]);
    }
    public function updateSettings(Request $r) {
        $this->confirm($r,true);
        $rules=['settings'=>'required|array:'.implode(',',array_keys(SystemSettingsService::SCHEMA))];
        foreach(SystemSettingsService::SCHEMA as $key=>$values)$rules['settings.'.$key]=['sometimes',...$values];
        $data=$r->validate($rules);
        DB::transaction(function()use($r,$data){foreach($data['settings'] as $key=>$value) DB::table('system_settings')->updateOrInsert(['key'=>$key],['value'=>json_encode($value),'created_at'=>now(),'updated_at'=>now()]);$this->audit($r,'settings.update','system',['fields'=>array_keys($data['settings'])]);});
        app(SystemSettingsService::class)->apply();return $this->settings();
    }
    public function providers(Request $r) {
        $out=[];foreach(SystemSettingsService::PROVIDERS as $id=>$path)$out[]=['id'=>$id,'configured'=>filled(config($path)),'source'=>DB::table('system_settings')->where('key','provider.'.$id)->exists()?'database':(filled(config($path))?'environment':'none'),'editable'=>$r->user()->role==='super_admin'];
        return response()->json(['providers'=>$out]);
    }
    public function updateProvider(Request $r,string $provider) {
        $this->confirm($r,true);abort_unless(isset(SystemSettingsService::PROVIDERS[$provider]),404);
        $data=$r->validate(['api_key'=>'required_without:clear|nullable|string|min:8|max:4096','clear'=>'sometimes|boolean']);
        DB::transaction(function()use($r,$data,$provider){
            DB::table('system_settings')->updateOrInsert(['key'=>'provider.'.$provider],['value'=>Crypt::encryptString(($data['clear']??false)?'':$data['api_key']),'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($r,($data['clear']??false)?'provider.disable':'provider.update',$provider);
        });
        app(SystemSettingsService::class)->apply();return $this->providers($r);
    }
    public function auditLog() {return DB::table('admin_audits')->orderByDesc('id')->paginate(30);}
    public function prune(Request $r) {$this->confirm($r,true);Artisan::call('humix:prune');$this->audit($r,'maintenance.prune');return response()->json(['success'=>true]);}
}
