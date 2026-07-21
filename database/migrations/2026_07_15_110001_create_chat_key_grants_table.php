<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_key_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('chat_id');
            $table->uuid('user_id');
            $table->string('device_id');
            // The chat's symmetric key, sealed (crypto_box_seal) to this
            // device's public key by whoever generated it — the server
            // never sees the key itself, only this opaque blob.
            $table->text('sealed_key');
            $table->timestamps();

            $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['chat_id', 'user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_key_grants');
    }
};
