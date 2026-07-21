<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // Comma-separated, mirroring how to_address already stores
            // multiple recipients (the IMAP library's Address collection
            // stringifies that way) — same convention, not a new one.
            $table->text('cc_address')->nullable()->after('to_address');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('cc_address');
        });
    }
};
