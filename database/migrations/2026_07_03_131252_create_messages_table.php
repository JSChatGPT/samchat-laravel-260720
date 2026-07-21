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
        DB::statement('
            CREATE TABLE messages (
                id UUID NOT NULL,
                chat_id UUID NOT NULL,
                sender_id UUID NOT NULL,
                message_type VARCHAR(255) NOT NULL,
                content TEXT,
                metadata JSONB,
                quoted_message_id UUID,
                is_forwarded BOOLEAN DEFAULT false,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);
        ');

        // Create a default partition
        DB::statement('CREATE TABLE messages_default PARTITION OF messages DEFAULT;');

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['chat_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS messages CASCADE;');
    }
};
