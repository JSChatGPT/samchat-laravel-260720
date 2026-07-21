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
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->uuid('chat_id');
            $table->uuid('user_id')->index();
            $table->boolean('is_muted')->default(false);
            $table->integer('unread_count')->default(0);
            $table->uuid('last_read_message_id')->nullable();
            $table->boolean('is_admin')->default(false);
            
            $table->primary(['chat_id', 'user_id']);
            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
