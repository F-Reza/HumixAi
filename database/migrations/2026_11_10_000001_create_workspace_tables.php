<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('plan', 32)->default('free');
            $table->unsignedInteger('daily_word_limit')->nullable();
            $table->boolean('history_enabled')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('source_text');
            $table->longText('current_text');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->longText('input_text');
            $table->longText('output_text')->nullable();
            $table->json('result')->nullable();
            $table->string('text_hash', 64);
            $table->timestamps();
            $table->index(['document_id', 'created_at']);
        });
        Schema::create('usage_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('used_words')->default(0);
            $table->unsignedInteger('reserved_words')->default(0);
            $table->unsignedInteger('word_limit');
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
        Schema::create('usage_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_day_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('words');
            $table->unsignedInteger('settled_words')->default(0);
            $table->string('action', 40);
            $table->string('reference_id', 100)->nullable();
            $table->string('status', 20)->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('snapshot');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('studio_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reservation_id', 36)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('action', 40);
            $table->json('payload');
            $table->json('result')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('stage', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['studio_jobs', 'document_shares', 'api_tokens', 'usage_reservations', 'usage_days', 'document_revisions', 'documents', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
