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
        Schema::table('calls', function (Blueprint $table) {
            $table->uuid('chat_id')->nullable()->after('caller_id');
            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
            
            // Make receiver_id nullable for group calls
            $table->uuid('receiver_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropForeign(['chat_id']);
            $table->dropColumn('chat_id');
            
            $table->uuid('receiver_id')->nullable(false)->change();
        });
    }
};
