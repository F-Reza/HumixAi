<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class VoiceProfile extends Model {
 use HasUuids;
 protected $fillable=['user_id','name','kind','language','sample','preferences','consented_at'];
 protected $hidden=['sample','preferences'];
 protected function casts():array{return ['sample'=>'encrypted','preferences'=>'encrypted','consented_at'=>'datetime'];}
}
