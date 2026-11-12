<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WritingFrame extends Model{
 public $timestamps=false;
 protected $guarded=['id'];
 protected function casts():array{return ['text'=>'encrypted','created_at'=>'datetime','sequence'=>'integer','characters'=>'integer'];}
}
