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
        // can't carry a real FK constraint (same reason message_receipts
        // doesn't have one either). An index is enough for our lookups.
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id');
            $table->uuid('user_id');
            $table->string('emoji');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
