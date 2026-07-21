<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('email_id');
            $table->string('file_name');
            // Relative path under the 'public' disk (storage/app/public/...)
            // — same convention as every other upload feature in this app
            // (status media, group photos, chat attachments).
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->foreign('email_id')->references('id')->on('emails')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
