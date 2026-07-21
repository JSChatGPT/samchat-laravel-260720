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
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_privacy')->default('contacts')->comment('everyone, contacts, selected, exclude');
            $table->json('status_privacy_list')->nullable()->comment('Array of user IDs for selected or exclude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status_privacy', 'status_privacy_list']);
        });
    }
};
