<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE vms DROP CONSTRAINT vms_user_id_foreign');
            DB::statement('ALTER TABLE vms ALTER COLUMN user_id DROP NOT NULL');
            DB::statement('ALTER TABLE vms ADD CONSTRAINT vms_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE vms DROP FOREIGN KEY vms_user_id_foreign');
            DB::statement('ALTER TABLE vms MODIFY user_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE vms ADD CONSTRAINT vms_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('UPDATE vms SET user_id = (SELECT id FROM users ORDER BY id LIMIT 1) WHERE user_id IS NULL');
            DB::statement('ALTER TABLE vms DROP CONSTRAINT vms_user_id_foreign');
            DB::statement('ALTER TABLE vms ALTER COLUMN user_id SET NOT NULL');
            DB::statement('ALTER TABLE vms ADD CONSTRAINT vms_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('UPDATE vms SET user_id = (SELECT id FROM users ORDER BY id LIMIT 1) WHERE user_id IS NULL');
            DB::statement('ALTER TABLE vms DROP FOREIGN KEY vms_user_id_foreign');
            DB::statement('ALTER TABLE vms MODIFY user_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE vms ADD CONSTRAINT vms_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        }
    }
};
