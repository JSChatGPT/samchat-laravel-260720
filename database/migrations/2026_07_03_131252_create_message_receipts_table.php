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
        Schema::create('message_receipts', function (Blueprint $table) {
            $table->uuid('message_id');
            $table->uuid('user_id');
            $table->enum('status', ['delivered', 'read']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_receipts');
    }
};
