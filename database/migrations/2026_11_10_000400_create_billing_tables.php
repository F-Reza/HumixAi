<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('customer_id')->nullable()->unique(); $table->string('subscription_id')->nullable()->unique();
            $table->string('status')->default('none'); $table->string('price_id')->nullable();
            $table->unsignedBigInteger('last_event_created')->default(0); $table->timestamps();
        });
        Schema::create('billing_events', function (Blueprint $table) {
            $table->string('id')->primary(); $table->string('type'); $table->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('billing_events'); Schema::dropIfExists('billing_accounts'); }
};
