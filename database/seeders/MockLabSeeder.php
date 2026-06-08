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
        $this->seedDemoUsers();

        if (app()->environment('testing')) {
            $this->seedTestingTemplates();

            return;
        }

        if (filter_var(env('ENABLE_DEMO_SEED_DATA', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->seedDisabledDemoPlaceholders();
        }
    }

    private function seedDemoUsers(): void
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
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'role' => $user['role'],
                ],
            );
        }
    }

    private function seedTestingTemplates(): void
    {
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
    }

    private function seedDisabledDemoPlaceholders(): void
    {
        VmTemplate::firstOrCreate(
            ['name' => '[DEMO DISABLED] Placeholder Template'],
            [
                'description' => 'Disabled placeholder only. Do not use for provisioning.',
                'proxmox_template_id' => 9999,
                'proxmox_node' => 'demo-disabled-placeholder',
                'cpu' => 1,
                'ram' => 1024,
                'disk' => 10,
                'ssh_username' => 'demo-disabled',
                'ssh_password' => null,
                'enabled' => false,
            ],
        );

        Vm::firstOrCreate(
            ['proxmox_id' => 'demo-disabled-placeholder-vm'],
            [
                'user_id' => null,
                'lab_template_id' => null,
                'vm_template_id' => null,
                'name' => '[DEMO DISABLED] Placeholder VM',
                'node' => 'demo-disabled-placeholder',
                'status' => 'stopped',
                'cpu_cores' => 1,
                'memory_mb' => 1024,
                'disk_gb' => 10,
                'metadata' => [
                    'demo' => true,
                    'disabled' => true,
                    'do_not_use_for_provisioning' => true,
                    'shared_practical' => true,
                    'managed_assignment' => true,
                    'ssh_host' => '127.0.0.1',
                    'ssh_port' => 22,
                    'ssh_username' => 'demo-disabled',
                    'notes' => 'Placeholder only. Do not store real SSH secrets in seeders.',
                ],
            ],
        );
    }
}
