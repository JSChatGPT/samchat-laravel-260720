<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('provider', ['gmail', 'yahoo']);
            $table->string('email_address');
            // Encrypted at rest via the model's 'encrypted' cast (Laravel's
            // AES-256-CBC using APP_KEY) — this is an app-specific password
            // the user generated themselves (see EmailAccountController),
            // not their real account password, but it's still a live
            // credential and must never be stored in plain text.
            $table->text('app_password');
            $table->string('imap_host');
            $table->integer('imap_port');
            $table->string('imap_encryption');
            $table->string('smtp_host');
            $table->integer('smtp_port');
            $table->string('smtp_encryption');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
