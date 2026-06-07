<?php

namespace Database\Seeders;

use App\Models\LabTemplate;
use App\Models\User;
use App\Models\Vm;
use App\Models\VmTemplate;
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
            ['name' => 'Admin Supervisor', 'email' => 'admin2@lab.test', 'role' => 'admin'],
            ['name' => 'Siswa 1', 'email' => 'siswa1@lab.test', 'role' => 'student'],
            ['name' => 'Siswa 2', 'email' => 'siswa2@lab.test', 'role' => 'student'],
            ['name' => 'Alfan Ridho', 'email' => 'alfanridho507@gmail.com', 'role' => 'admin'],
            ['name' => 'Demo Admin', 'email' => 'admin@example.com', 'role' => 'admin'],
            ['name' => 'Demo User', 'email' => 'user@example.com', 'role' => 'student'],
            ['name' => 'Demo Student', 'email' => 'student@example.com', 'role' => 'student'],
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

        foreach ($templates as $index => $template) {
            VmTemplate::updateOrCreate(
                ['name' => $template['name']],
                [
                    'description' => $template['description'],
                    'proxmox_template_id' => 9000 + $index,
                    'proxmox_node' => 'pve-mock',
                    'cpu' => $template['cpu_cores'],
                    'ram' => $template['memory_mb'],
                    'disk' => $template['disk_gb'],
                    'ssh_username' => 'student',
                    'enabled' => true,
                ],
            );
        }

        VmTemplate::updateOrCreate(
            ['name' => 'Demo Ubuntu Template'],
            [
                'description' => 'Disabled placeholder template for documentation and demo screens only.',
                'proxmox_template_id' => 9999,
                'proxmox_node' => 'pve-placeholder',
                'cpu' => 1,
                'ram' => 1024,
                'disk' => 10,
                'ssh_username' => 'student',
                'ssh_password' => null,
                'enabled' => false,
            ],
        );

        if (! app()->environment('testing')) {
            Vm::updateOrCreate(
                ['proxmox_id' => 'demo-shared-practical-placeholder'],
                [
                    'user_id' => null,
                    'lab_template_id' => null,
                    'vm_template_id' => null,
                    'name' => 'Demo Shared Practical VM',
                    'node' => 'pve-placeholder',
                    'status' => 'stopped',
                    'cpu_cores' => 1,
                    'memory_mb' => 1024,
                    'disk_gb' => 10,
                    'metadata' => [
                        'demo' => true,
                        'inactive' => true,
                        'shared_practical' => true,
                        'managed_assignment' => true,
                        'ssh_host' => '127.0.0.1',
                        'ssh_port' => 22,
                        'ssh_username' => 'student',
                        'notes' => 'Placeholder only. Do not store real SSH secrets in seeders.',
                    ],
                ],
            );
        }
    }
}
