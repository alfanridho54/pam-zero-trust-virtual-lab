<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practical_vm_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('vms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vm_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practical_vm_accesses');
    }
};
