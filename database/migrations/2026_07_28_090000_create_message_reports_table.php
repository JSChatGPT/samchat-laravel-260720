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
        // `messages` is a partitioned table with a composite primary key
        // (id, created_at) — see create_messages_table — so message_id here
        // can't carry a real FK constraint, same reason message_reactions
        // doesn't have one either. An index is enough for our lookups.
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id');
            $table->uuid('reporter_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->timestamps();

            $table->foreign('reporter_id')->references('id')->on('users')->onDelete('cascade');
            // One report per reporter per message — resubmitting just updates
            // the reason/details (see ChatController::reportMessage) instead
            // of piling up duplicate rows for the same complaint.
            $table->unique(['message_id', 'reporter_id']);
            $table->index('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reports');
    }
};
