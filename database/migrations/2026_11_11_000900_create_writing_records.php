<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{
  Schema::create('writing_records',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->foreignUuid('document_id')->nullable()->constrained()->cascadeOnDelete();$t->string('title',200);$t->boolean('active')->default(false);$t->timestamp('consented_at');$t->unsignedInteger('stored_bytes')->default(0);$t->unsignedInteger('frame_count')->default(0);$t->timestamps();});
  Schema::create('writing_frames',function(Blueprint $t){$t->id();$t->foreignUuid('writing_record_id')->constrained()->cascadeOnDelete();$t->uuid('event_id');$t->unsignedInteger('sequence');$t->string('kind',20);$t->longText('text');$t->string('text_hash',64);$t->unsignedInteger('characters');$t->timestamp('created_at');$t->unique(['writing_record_id','event_id']);$t->unique(['writing_record_id','sequence']);});
 }
 public function down():void{Schema::dropIfExists('writing_frames');Schema::dropIfExists('writing_records');}
};
