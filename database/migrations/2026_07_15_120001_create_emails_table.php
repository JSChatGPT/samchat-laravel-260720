<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('email_account_id');
            // IMAP UID is only unique within a folder on one account, and
            // IMAP's SINCE search is date- (not time-) granular so the sync
            // job can re-fetch the same day's messages more than once —
            // this pair is what updateOrCreate dedupes on in EmailSyncService.
            $table->string('uid');
            $table->string('folder')->default('INBOX');
            $table->string('message_id')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->text('to_address')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_outgoing')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->foreign('email_account_id')->references('id')->on('email_accounts')->onDelete('cascade');
            $table->unique(['email_account_id', 'folder', 'uid']);
            $table->index(['email_account_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
