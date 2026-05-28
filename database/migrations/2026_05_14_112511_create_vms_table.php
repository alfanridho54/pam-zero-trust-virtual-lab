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
        Schema::create('vms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('lab_template_id')->nullable()->index();
            $table->string('name');
            $table->string('proxmox_id')->nullable()->unique();
            $table->string('node')->default('pve-mock');
            $table->string('status')->default('stopped');
            $table->unsignedInteger('cpu_cores')->default(1);
            $table->unsignedInteger('memory_mb')->default(1024);
            $table->unsignedInteger('disk_gb')->default(10);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vms');
    }
};
