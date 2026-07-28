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
        // Mirrors message_reports — reporting a whole profile rather than a
        // single message (see UserController::report).
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('reporter_id');
            $table->uuid('reported_user_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->timestamps();

            $table->foreign('reporter_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reported_user_id')->references('id')->on('users')->onDelete('cascade');
            // One report per reporter per reported user — resubmitting just
            // updates the reason/details instead of piling up duplicates.
            $table->unique(['reporter_id', 'reported_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};
