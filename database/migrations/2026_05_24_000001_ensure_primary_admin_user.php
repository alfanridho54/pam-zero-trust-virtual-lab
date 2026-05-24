<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        $email = 'alfanridho507@gmail.com';

        if (DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')
                ->where('email', $email)
                ->update([
                    'role' => 'admin',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
