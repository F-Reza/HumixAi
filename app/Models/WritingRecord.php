<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class WritingRecord extends Model{
 use HasUuids;
 protected $guarded=['id'];
 protected function casts():array{return ['active'=>'boolean','consented_at'=>'datetime','frame_count'=>'integer','stored_bytes'=>'integer'];}
 public function frames(){return $this->hasMany(WritingFrame::class)->orderBy('sequence');}
}
