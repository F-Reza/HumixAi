<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function(Blueprint $t) { $t->string('role', 20)->default('user')->index(); $t->string('locale', 2)->default('en'); $t->timestamp('suspended_at')->nullable(); $t->unsignedInteger('quota_override')->nullable(); });
        Schema::create('system_settings', function(Blueprint $t) { $t->string('key')->primary(); $t->text('value'); $t->timestamps(); });
        Schema::create('admin_audits', function(Blueprint $t) { $t->id(); $t->unsignedBigInteger('actor_id')->nullable()->index(); $t->string('action',80); $t->string('target',100)->nullable(); $t->json('metadata'); $t->timestamp('created_at')->index(); });
    }
    public function down(): void { Schema::dropIfExists('admin_audits'); Schema::dropIfExists('system_settings'); Schema::table('users', fn(Blueprint $t)=>$t->dropColumn(['role','locale','suspended_at','quota_override'])); }
};
