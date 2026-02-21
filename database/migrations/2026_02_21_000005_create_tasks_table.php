<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('created_from')->default('chat');
            $table->string('chat_message_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('normal');
            $table->string('schedule_type');
            $table->timestamp('run_at')->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('cron_human')->nullable();
            $table->text('event_condition')->nullable();
            $table->string('timezone')->default('UTC');
            $table->json('execution_plan')->nullable();
            $table->text('expected_output')->nullable();
            $table->text('original_user_request')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'schedule_type', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
