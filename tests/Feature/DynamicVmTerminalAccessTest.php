<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use App\Services\SshCommandResult;
use App\Services\SshCommandService;
use App\Services\SshReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicVmTerminalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_open_terminal_for_own_running_provisioned_vm_with_ssh_metadata(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2401,
                'ssh_host' => '10.10.10.21',
                'ssh_port' => 2222,
                'ssh_username' => 'labuser',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'vmid' => 2401,
            'ssh_host' => '10.10.10.21',
            'ssh_port' => 2222,
            'ssh_username' => 'labuser',
            'status' => TerminalSessionStatus::Pending->value,
        ]);
    }

    public function test_provisioned_vm_without_ssh_metadata_returns_safe_error(): void
    {
        config(['services.terminal.target_host' => '192.0.2.10']);

        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2402,
            ],
        ]);

        $this->actingAs($student)
            ->from(route('student.vms.index'))
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('error', 'SSH access for this VM is not configured yet.');

        $this->assertDatabaseMissing('terminal_sessions', [
            'vm_id' => $vm->id,
        ]);
    }

    public function test_terminal_open_lazily_detects_and_stores_guest_ip_when_ssh_host_missing(): void
    {
        config(['services.terminal.target_host' => '192.0.2.10']);

        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'node' => 'pve1',
            'proxmox_id' => '2412',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2412,
                'ssh_username' => 'labuser',
            ],
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve1' && $vmid === 2412 ? '172.16.1.18' : null;
            }
        });

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();

        $this->assertSame('172.16.1.18', $vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.18', $vm->metadata['ip_address']);
        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'ssh_host' => '172.16.1.18',
            'ssh_username' => 'labuser',
        ]);
    }

    public function test_terminal_open_keeps_safe_error_when_lazy_guest_ip_detection_fails(): void
    {
        config(['services.terminal.target_host' => '192.0.2.10']);

        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'node' => 'pve1',
            'proxmox_id' => '2413',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2413,
            ],
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return null;
            }
        });

        $this->actingAs($student)
            ->from(route('student.vms.index'))
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('error', 'SSH access for this VM is not configured yet.');

        $this->assertArrayNotHasKey('ssh_host', $vm->refresh()->metadata ?? []);
        $this->assertDatabaseMissing('terminal_sessions', [
            'vm_id' => $vm->id,
        ]);
    }

    public function test_provisioned_vm_using_ip_address_fallback_can_open_terminal(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2407,
                'ip_address' => '10.10.10.27',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'vm_id' => $vm->id,
            'ssh_host' => '10.10.10.27',
        ]);
    }

    public function test_provisioned_vm_using_private_ip_fallback_can_open_terminal(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2408,
                'private_ip' => '10.10.10.28',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'vm_id' => $vm->id,
            'ssh_host' => '10.10.10.28',
        ]);
    }

    public function test_terminal_session_is_created_with_waiting_message_when_ssh_is_delayed(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2415,
                'ssh_host' => '172.16.1.20',
            ],
        ]);

        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                return false;
            }
        });

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status', 'Terminal session dibuat. SSH VM masih disiapkan, silakan tunggu sebentar lalu refresh halaman ini.');

        $terminalSession = TerminalSession::where('vm_id', $vm->id)->firstOrFail();

        $this->assertSame(TerminalSessionStatus::Pending, $terminalSession->status);
        $this->assertFalse($terminalSession->metadata['ssh_ready']);
        $this->assertSame('running', $vm->refresh()->status);
    }

    public function test_terminal_page_recovers_when_delayed_ssh_becomes_ready(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2416,
                'ssh_host' => '172.16.1.21',
            ],
        ]);

        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            private int $calls = 0;

            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                $this->calls++;

                return $this->calls >= 2;
            }
        });

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status', 'Terminal session dibuat. SSH VM masih disiapkan, silakan tunggu sebentar lalu refresh halaman ini.');

        $terminalSession = TerminalSession::where('vm_id', $vm->id)->firstOrFail();

        $this->actingAs($student)
            ->get(route('terminal-sessions.show', $terminalSession))
            ->assertOk()
            ->assertDontSee('SSH is still starting inside this VM.');

        $this->assertTrue($terminalSession->refresh()->metadata['ssh_ready']);
    }

    public function test_ssh_readiness_helper_retries_until_success(): void
    {
        $readiness = new class extends SshReadinessService
        {
            public int $calls = 0;

            protected function canConnect(string $host, int $port, float $timeoutSeconds): bool
            {
                $this->calls++;

                return $this->calls === 3;
            }
        };

        $this->assertTrue($readiness->waitUntilReachable('172.16.1.22', 22, attempts: 5, delayMilliseconds: 0, timeoutSeconds: 0.01));
        $this->assertSame(3, $readiness->calls);
    }

    public function test_ssh_readiness_helper_times_out_safely(): void
    {
        $readiness = new class extends SshReadinessService
        {
            public int $calls = 0;

            protected function canConnect(string $host, int $port, float $timeoutSeconds): bool
            {
                $this->calls++;

                return false;
            }
        };

        $this->assertFalse($readiness->waitUntilReachable('172.16.1.23', 22, attempts: 5, delayMilliseconds: 0, timeoutSeconds: 0.01));
        $this->assertSame(5, $readiness->calls);
    }

    public function test_stopped_vm_returns_safe_terminal_error(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'stopped',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2403,
                'ssh_host' => '10.10.10.23',
            ],
        ]);

        $this->actingAs($student)
            ->from(route('student.vms.index'))
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('error', 'Start this VM before opening terminal access.');

        $this->assertDatabaseMissing('terminal_sessions', [
            'vm_id' => $vm->id,
        ]);
    }

    public function test_student_cannot_open_terminal_for_another_students_vm(): void
    {
        $student = $this->student();
        $otherStudent = $this->student();
        $vm = $this->studentVm($otherStudent, [
            'status' => 'running',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2404,
                'ssh_host' => '10.10.10.24',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertForbidden();
    }

    public function test_static_terminal_flow_can_still_use_configured_fallback_host(): void
    {
        config(['services.terminal.target_host' => '192.0.2.50']);

        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'running',
            'metadata' => [
                'vmid' => 2405,
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'vm_id' => $vm->id,
            'ssh_host' => '192.0.2.50',
        ]);
    }

    public function test_command_against_stopped_vm_is_logged_as_blocked_and_touches_activity(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'status' => 'stopped',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2406,
                'ssh_host' => '10.10.10.26',
            ],
        ]);
        $terminalSession = TerminalSession::create([
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => $vm->proxmoxVmid(),
            'ssh_host' => '10.10.10.26',
            'ssh_port' => 22,
            'ssh_username' => 'student',
            'status' => TerminalSessionStatus::Active,
            'started_at' => now(),
            'last_activity_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for stopped VM.');
            }
        });

        $this->actingAs($student)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'whoami',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Start this VM before opening terminal access.');

        $terminalSession->refresh();

        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'vm_id' => $vm->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Blocked->value,
            'blocked_reason' => 'Start this VM before opening terminal access.',
        ]);
    }

    public function test_secrets_are_not_displayed_on_terminal_page(): void
    {
        $student = $this->student();
        $secretPassword = 'super-secret-student-password';
        $secretPrivateKey = "-----BEGIN PRIVATE KEY-----\nsecret-key-body\n-----END PRIVATE KEY-----";
        $vm = $this->studentVm($student, [
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2409,
                'ssh_host' => '10.10.10.29',
                'ssh_password' => $secretPassword,
                'ssh_private_key' => $secretPrivateKey,
            ],
        ]);
        $terminalSession = TerminalSession::create([
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => $vm->proxmoxVmid(),
            'ssh_host' => '10.10.10.29',
            'ssh_port' => 22,
            'ssh_username' => 'student',
            'status' => TerminalSessionStatus::Pending,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
        ]);

        $this->actingAs($student)
            ->get(route('terminal-sessions.show', $terminalSession))
            ->assertOk()
            ->assertDontSee($secretPassword)
            ->assertDontSee('secret-key-body');
    }

    public function test_admin_can_update_vm_ssh_metadata_without_auditing_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2410,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.ssh-metadata.update', $vm), [
                'ssh_host' => '10.10.10.30',
                'ssh_port' => 2222,
                'ssh_username' => 'labuser',
                'ssh_password' => 'admin-set-secret',
                'ssh_private_key' => 'admin-set-private-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();

        $this->assertSame('10.10.10.30', $vm->metadata['ssh_host']);
        $this->assertSame('admin-set-secret', $vm->metadata['ssh_password']);
        $this->assertDatabaseHas('audit_logs', [
            'vm_id' => $vm->id,
            'action' => 'dashboard.vm.ssh_metadata.updated',
        ]);

        $auditMetadata = json_encode(AuditLog::where('action', 'dashboard.vm.ssh_metadata.updated')->firstOrFail()->metadata);

        $this->assertStringNotContainsString('admin-set-secret', $auditMetadata);
        $this->assertStringNotContainsString('admin-set-private-key', $auditMetadata);
    }

    public function test_student_cannot_update_vm_ssh_metadata(): void
    {
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2411,
            ],
        ]);

        $this->actingAs($student)
            ->post(route('dashboard.vms.ssh-metadata.update', $vm), [
                'ssh_host' => '10.10.10.31',
            ])
            ->assertForbidden();

        $this->assertArrayNotHasKey('ssh_host', $vm->refresh()->metadata ?? []);
    }

    public function test_admin_can_refresh_vm_ssh_metadata_from_guest_agent_without_exposing_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = $this->student();
        $vm = $this->studentVm($student, [
            'node' => 'pve1',
            'proxmox_id' => '2414',
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 2414,
                'ssh_password' => 'hidden-secret',
            ],
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve1' && $vmid === 2414 ? '172.16.1.19' : null;
            }
        });

        $this->actingAs($admin)
            ->post(route('dashboard.vms.ssh-metadata.refresh', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();

        $this->assertSame('172.16.1.19', $vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.19', $vm->metadata['ip_address']);
        $this->assertSame('hidden-secret', $vm->metadata['ssh_password']);

        $auditMetadata = json_encode(AuditLog::where('action', 'dashboard.vm.ssh_metadata.refresh.success')->firstOrFail()->metadata);

        $this->assertStringNotContainsString('hidden-secret', $auditMetadata);
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => 'student',
        ]);
    }

    private function studentVm(User $student, array $attributes = []): Vm
    {
        return Vm::create([
            'user_id' => $student->id,
            'name' => 'student-lab-vm',
            'proxmox_id' => '2400-'.str()->random(8),
            'node' => 'pve-test',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [],
            ...$attributes,
        ]);
    }
}
