<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vm_id')->constrained('vms')->cascadeOnDelete();
            $table->text('command');
            $table->string('status', 32);
            $table->string('blocked_reason')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->mediumText('output_excerpt')->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['terminal_session_id', 'executed_at']);
            $table->index(['user_id', 'executed_at']);
            $table->index(['vm_id', 'executed_at']);
            $table->index(['status', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_logs');
    }
};
