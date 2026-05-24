<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vm_id')->constrained('vms')->cascadeOnDelete();
            $table->string('node');
            $table->string('proxmox_id')->nullable();
            $table->unsignedBigInteger('vmid')->nullable();
            $table->string('ssh_host');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_username');
            $table->string('status', 32)->default('active');
            $table->ipAddress('client_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['vm_id', 'status']);
            $table->index(['node', 'vmid']);
            $table->index(['started_at']);
            $table->index(['last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_sessions');
    }
};
