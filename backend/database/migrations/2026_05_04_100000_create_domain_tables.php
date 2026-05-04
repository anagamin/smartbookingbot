<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 64);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_kopecks')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
            $table->index(['user_id', 'weekday']);
        });

        Schema::create('dialogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_client_id', 64);
            $table->string('client_name')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'external_client_id'], 'dialogs_social_peer_unique');
        });

        Schema::create('dialog_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dialog_id')->constrained()->cascadeOnDelete();
            $table->string('state', 64)->default('open');
            $table->string('intent', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['dialog_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dialog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dialog_session_id')->constrained('dialog_sessions')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->text('text');
            $table->timestamps();
            $table->index(['dialog_session_id', 'created_at']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dialog_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('price_kopecks')->nullable();
            $table->text('chat_excerpt')->nullable();
            $table->string('status', 32)->default('confirmed');
            $table->timestamps();
            $table->index(['user_id', 'starts_at']);
            $table->index(['user_id', 'ends_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('summary');
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_kopecks');
            $table->string('type', 32);
            $table->string('external_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('vk_processed_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vk_group_id');
            $table->string('event_id', 64);
            $table->timestamps();
            $table->unique(['vk_group_id', 'event_id']);
        });

        Schema::create('oauth_pkce_states', function (Blueprint $table) {
            $table->string('state', 128)->primary();
            $table->string('code_verifier', 256);
            $table->string('provider', 32);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_pkce_states');
        Schema::dropIfExists('vk_processed_events');
        Schema::dropIfExists('billing_transactions');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('dialog_sessions');
        Schema::dropIfExists('dialogs');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('services');
        Schema::dropIfExists('social_accounts');
    }
};
