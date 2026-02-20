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
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conversation_id');
            $table->string('role', 32);
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('chat_conversations')
                ->cascadeOnDelete();

            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
