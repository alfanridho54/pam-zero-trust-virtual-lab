<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_sessions', function (Blueprint $table) {
            $table->string('session_token', 128)->nullable()->unique()->after('session_uuid');
            $table->timestamp('expires_at')->nullable()->after('last_activity_at');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('terminal_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropUnique(['session_token']);
            $table->dropColumn(['session_token', 'expires_at']);
        });
    }
};
