<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gmail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('google_email')->unique();
            $table->string('display_name')->nullable();
            $table->json('scopes')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('gmail_history_id')->nullable();
            $table->timestamp('watch_expires_at')->nullable();
            $table->string('sync_status')->default('not_connected');
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('gmail_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_account_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_thread_id');
            $table->string('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->json('participants')->nullable();
            $table->timestamp('latest_message_at')->nullable();
            $table->string('classification')->nullable();
            $table->decimal('classification_confidence', 5, 2)->nullable();
            $table->text('classification_reason')->nullable();
            $table->string('status')->default('pending_review');
            $table->timestamps();

            $table->unique(['gmail_account_id', 'gmail_thread_id']);
            $table->index(['gmail_account_id', 'classification']);
            $table->index(['gmail_account_id', 'latest_message_at']);
        });

        Schema::create('gmail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gmail_thread_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_message_id');
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->json('recipients')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('gmail_received_at')->nullable();
            $table->boolean('is_unread')->default(true);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['gmail_account_id', 'gmail_message_id']);
            $table->index(['gmail_thread_id', 'gmail_received_at']);
        });

        Schema::create('reply_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gmail_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gmail_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('draft_subject')->nullable();
            $table->longText('draft_body')->nullable();
            $table->string('status')->default('generated');
            $table->string('generation_source')->default('stub');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['gmail_thread_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reply_drafts');
        Schema::dropIfExists('gmail_messages');
        Schema::dropIfExists('gmail_threads');
        Schema::dropIfExists('gmail_accounts');
    }
};