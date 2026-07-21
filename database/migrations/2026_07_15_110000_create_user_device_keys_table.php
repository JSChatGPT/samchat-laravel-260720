<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            // Client-generated UUID, one per app install / browser — a user
            // can have several (phone + web + a second phone), each with
            // its own independent X25519 keypair (see chat_key_grants).
            $table->string('device_id');
            $table->text('public_key');
            $table->enum('platform', ['android', 'ios', 'web'])->default('web');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_keys');
    }
};
