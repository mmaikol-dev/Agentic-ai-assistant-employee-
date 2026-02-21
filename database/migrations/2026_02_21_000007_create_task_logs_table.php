<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->uuid('run_id');
            $table->foreign('run_id')->references('id')->on('task_runs')->cascadeOnDelete();
            $table->unsignedInteger('step');
            $table->string('status');
            $table->text('thought')->nullable();
            $table->text('action')->nullable();
            $table->text('observation')->nullable();
            $table->string('tool_used')->nullable();
            $table->json('tool_input')->nullable();
            $table->json('tool_output')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();

            $table->index(['task_id', 'logged_at']);
            $table->index(['run_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_logs');
    }
};
