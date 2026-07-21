<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('host_id');
            // Backing group chat used to actually run the call when the
            // meeting starts (CallController::initiate already knows how to
            // fan a call out to every chat participant) and to give
            // attendees a thread to coordinate in beforehand.
            $table->uuid('chat_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('call_type', ['audio', 'video'])->default('video');
            $table->timestamp('scheduled_at');
            $table->integer('duration_minutes')->default(30);
            $table->timestamp('started_at')->nullable();
            // Set once the ~10-minute-before reminder push has gone out, so
            // the scheduled command (which runs frequently) never double-sends.
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('host_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('set null');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
