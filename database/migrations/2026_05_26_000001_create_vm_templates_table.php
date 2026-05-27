<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vm_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('proxmox_template_id');
            $table->string('proxmox_node');
            $table->unsignedInteger('cpu')->default(1);
            $table->unsignedInteger('ram')->default(1024);
            $table->unsignedInteger('disk')->default(10);
            $table->string('ssh_username')->default('student');
            $table->text('ssh_password')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'name']);
            $table->unique(['proxmox_node', 'proxmox_template_id']);
        });

        Schema::table('vms', function (Blueprint $table) {
            $table->foreignId('vm_template_id')
                ->nullable()
                ->after('lab_template_id')
                ->constrained('vm_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vm_template_id');
        });

        Schema::dropIfExists('vm_templates');
    }
};
