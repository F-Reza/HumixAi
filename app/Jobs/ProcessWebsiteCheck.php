<?php
namespace App\Jobs;
use App\Models\{WebsiteCheck,User};
use App\Services\{UrlImportService,StudioRunner};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class ProcessWebsiteCheck implements ShouldQueue{
 use Queueable;
 public int $tries=1;public int $timeout=180;public bool $failOnTimeout=true;
 public function __construct(public string $checkId,public string $token){}
 public function handle(UrlImportService $imports,StudioRunner $runner):void{
  $check=WebsiteCheck::whereKey($this->checkId)->where('run_token',$this->token)->where('active',true)->first();
  if(!$check || !WebsiteCheck::whereKey($check->id)->where('run_token',$this->token)->where('state','queued')->where('running_until','>',now())->update(['state'=>'running']))return;
  try{
   $user=User::find($check->user_id);if(!$user || $user->suspended_at)throw new \RuntimeException('Account unavailable');
   app()->setLocale(\App\Support\Locales::normalize($user->locale));
   $preview=$imports->preview($check->url);
   // Recheck cancellation after network I/O and before billable detection.
   if(!WebsiteCheck::whereKey($check->id)->where('run_token',$this->token)->where('active',true)->exists())return;
   if($user->fresh()?->suspended_at)throw new \RuntimeException('Account unavailable');
   $payload=['text'=>$preview['text'],'language'=>$check->language,'external_processing_consent'=>true];
   $reservation=$runner->reserve($user,'detect',$payload);
   if(!WebsiteCheck::whereKey($check->id)->where('run_token',$this->token)->where('active',true)->update(['reservation_id'=>$reservation['reservation_id']])){app(\App\Services\UsageService::class)->release($reservation['reservation_id']);return;}
   $result=$runner->execute($user,'detect',$payload,$reservation['reservation_id']);
   $report=\Illuminate\Support\Arr::only($result,['status','provider','model_version','overall_score','score_type','score_label','classification','class_probabilities','language','language_notice','text_hash','word_count','character_count','analyzed_characters','scanned_at','message']);
   WebsiteCheck::whereKey($check->id)->where('run_token',$this->token)->where('active',true)->update(['state'=>$result['status']==='completed'?'completed':'limited','report'=>json_encode($report),'last_checked_at'=>now(),'run_token'=>null,'running_until'=>null,'reservation_id'=>null]);
  }catch(\Throwable){$this->failed(null);}
 }
 public function failed(?\Throwable $exception):void{
  $check=WebsiteCheck::whereKey($this->checkId)->where('run_token',$this->token)->first();
  if(!$check)return;
  if($check->reservation_id && \App\Models\UsageReservation::whereKey($check->reservation_id)->exists())app(\App\Services\UsageService::class)->release($check->reservation_id);
  WebsiteCheck::whereKey($this->checkId)->where('run_token',$this->token)->update(['state'=>$check->active?'failed':'paused','report'=>json_encode(['status'=>'error','overall_score'=>null,'message'=>__('The page check could not finish. Check URL access, provider configuration and available allowance.')]),'last_checked_at'=>now(),'run_token'=>null,'running_until'=>null,'reservation_id'=>null]);
 }
}
