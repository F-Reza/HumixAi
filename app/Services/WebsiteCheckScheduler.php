<?php
namespace App\Services;
use App\Models\WebsiteCheck;
use App\Jobs\ProcessWebsiteCheck;
use Illuminate\Support\Str;
class WebsiteCheckScheduler{
 public function dispatch(WebsiteCheck $check,bool $manual=false):bool{
  $token=(string)Str::uuid();
  $query=WebsiteCheck::whereKey($check->id)->where('active',true)->where(fn($q)=>$q->whereNull('running_until')->orWhere('running_until','<',now()));
  if(!$manual)$query->where('next_run_at','<=',now());
  if(!$query->update(['run_token'=>$token,'reservation_id'=>null,'state'=>'queued','running_until'=>now()->addMinutes(10),'next_run_at'=>$check->frequency==='weekly'?now()->addWeek():now()->addDay()]))return false;
  try{ProcessWebsiteCheck::dispatch($check->id,$token)->onConnection(config('studio.queue_connection','database'));}
  catch(\Throwable){WebsiteCheck::whereKey($check->id)->where('run_token',$token)->update(['running_until'=>null,'run_token'=>null,'state'=>'failed','next_run_at'=>now()->addHour()]);return false;}
  return true;
 }
}
