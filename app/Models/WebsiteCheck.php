<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class WebsiteCheck extends Model{
 use HasUuids;
 protected $guarded=['id'];
 protected function casts():array{return ['active'=>'boolean','consented_at'=>'datetime','next_run_at'=>'datetime','running_until'=>'datetime','last_checked_at'=>'datetime','report'=>'array'];}
}
