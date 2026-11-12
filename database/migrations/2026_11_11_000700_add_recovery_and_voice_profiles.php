<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('password_reset_tokens',function(Blueprint $t){$t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();});
  Schema::create('voice_profiles',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name',100);$t->string('kind',20)->default('personal');$t->string('language',4)->default('auto');$t->text('sample');$t->text('preferences')->nullable();$t->timestamp('consented_at');$t->timestamps();$t->index(['user_id','created_at']);});
 }
 public function down(): void {Schema::dropIfExists('voice_profiles');Schema::dropIfExists('password_reset_tokens');}
};
