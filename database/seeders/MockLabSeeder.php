<?php

namespace Database\Seeders;

use App\Models\LabTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MockLabSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            ['name' => 'Admin Lab', 'email' => 'admin@lab.test', 'role' => 'admin'],
            ['name' => 'Teacher Lab', 'email' => 'teacher@lab.test', 'role' => 'guru'],
            ['name' => 'Siswa 1', 'email' => 'siswa1@lab.test', 'role' => 'student'],
            ['name' => 'Siswa 2', 'email' => 'siswa2@lab.test', 'role' => 'student'],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'role' => $user['role'],
                ],
            );
        }

        $templates = [
            [
                'name' => 'Linux Basic',
                'description' => 'Template dasar Linux untuk latihan terminal dan administrasi sistem.',
                'proxmox_template_id' => 'tpl-linux-basic',
                'cpu_cores' => 1,
                'memory_mb' => 1024,
                'disk_gb' => 10,
            ],
            [
                'name' => 'Docker Lab',
                'description' => 'Template praktikum container menggunakan Docker.',
                'proxmox_template_id' => 'tpl-docker-lab',
                'cpu_cores' => 2,
                'memory_mb' => 2048,
                'disk_gb' => 20,
            ],
            [
                'name' => 'Podman Lab',
                'description' => 'Template praktikum container rootless menggunakan Podman.',
                'proxmox_template_id' => 'tpl-podman-lab',
                'cpu_cores' => 2,
                'memory_mb' => 2048,
                'disk_gb' => 20,
            ],
            [
                'name' => 'Web Server Lab',
                'description' => 'Template praktikum deploy dan konfigurasi web server.',
                'proxmox_template_id' => 'tpl-web-server-lab',
                'cpu_cores' => 2,
                'memory_mb' => 2048,
                'disk_gb' => 20,
            ],
        ];

        foreach ($templates as $template) {
            LabTemplate::updateOrCreate(
                ['name' => $template['name']],
                [
                    ...$template,
                    'node' => 'pve-mock',
                    'is_active' => true,
                ],
            );
        }
    }
}
