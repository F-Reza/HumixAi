<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('website_checks',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->text('url');$t->string('language',4);$t->string('frequency',10);$t->boolean('active')->default(true);$t->timestamp('consented_at');$t->timestamp('next_run_at')->index();$t->timestamp('running_until')->nullable();$t->uuid('run_token')->nullable();$t->uuid('reservation_id')->nullable();$t->string('state',20)->default('scheduled');$t->json('report')->nullable();$t->timestamp('last_checked_at')->nullable();$t->timestamps();});}
 public function down():void{Schema::dropIfExists('website_checks');}
};
