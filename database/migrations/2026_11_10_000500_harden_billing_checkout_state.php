<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->json('remote_subscriptions')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('checkout_session_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('checkout_status', 30)->nullable();
            $table->string('checkout_plan', 50)->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->string('checkout_request_key', 100)->nullable();
            $table->json('checkout_request_payload')->nullable();
            $table->timestamp('checkout_request_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', fn (Blueprint $table) => $table->dropColumn([
            'remote_subscriptions', 'reconciled_at', 'checkout_session_id', 'checkout_url', 'checkout_status',
            'checkout_plan', 'checkout_expires_at', 'checkout_request_key', 'checkout_request_payload', 'checkout_request_started_at',
        ]));
    }
};
