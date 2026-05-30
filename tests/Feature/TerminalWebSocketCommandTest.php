<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use App\Services\SshCommandResult;
use App\Services\SshCommandService;
use App\Services\TerminalWebSocketCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalWebSocketCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_owned_session_can_run_websocket_command_service_path(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 12,
                    output: "student\nubuntu-student-01\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami && hostname');

        $terminalSession->refresh();

        $this->assertSame('output', $result->type);
        $this->assertSame(CommandLogStatus::Succeeded->value, $result->status);
        $this->assertStringContainsString('ubuntu-student-01', $result->output);
        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'whoami && hostname',
            'status' => CommandLogStatus::Succeeded->value,
        ]);
    }

    public function test_shared_practical_session_can_run_websocket_command_service_path(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 12,
                    output: "student\nshared-practical\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami');

        $this->assertSame('output', $result->type);
        $this->assertSame(CommandLogStatus::Succeeded->value, $result->status);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'vm_id' => $terminalSession->vm_id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Succeeded->value,
        ]);
    }

    public function test_shared_practical_websocket_command_uses_refreshed_ip(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'vmid' => 2601,
            'ssh_host' => '172.16.1.30',
        ], vmMetadata: [
            'vmid' => 2601,
            'ssh_host' => '172.16.1.30',
            'ip_address' => '172.16.1.30',
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve-mock' && $vmid === 2601 ? '172.16.1.29' : null;
            }
        });

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: $terminalSession->ssh_host === '172.16.1.29',
                    exitCode: $terminalSession->ssh_host === '172.16.1.29' ? 0 : 1,
                    durationMs: 12,
                    output: $terminalSession->ssh_host,
                    error: $terminalSession->ssh_host === '172.16.1.29' ? '' : 'stale host',
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami');

        $terminalSession->refresh();
        $terminalSession->vm->refresh();

        $this->assertSame('output', $result->type);
        $this->assertSame('172.16.1.29', $terminalSession->ssh_host);
        $this->assertSame('172.16.1.29', $terminalSession->vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.29', $terminalSession->vm->metadata['ip_address']);
    }

    public function test_websocket_command_requires_shared_practical_access(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
        ], grantAccess: false);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not run without shared practical access.');
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami');

        $this->assertSame('blocked', $result->type);
        $this->assertSame(CommandLogStatus::Blocked->value, $result->status);
        $this->assertStringContainsString('Anda tidak memiliki akses ke session terminal ini. Debug:', $result->output);
        $this->assertStringContainsString('returned_by=App\Policies\CommandLogPolicy::execute', $result->output);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    public function test_websocket_command_cannot_use_another_users_terminal_session(): void
    {
        $owner = $this->student();
        $otherStudent = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($owner, [
            'status' => TerminalSessionStatus::Active,
        ]);
        $terminalSession->vm->practicalAccesses()->create([
            'user_id' => $otherStudent->id,
        ]);

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $otherStudent, 'whoami');

        $this->assertSame('blocked', $result->type);
        $this->assertSame(CommandLogStatus::Blocked->value, $result->status);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $otherStudent->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    public function test_dynamic_vm_websocket_command_uses_vm_metadata_password(): void
    {
        config(['services.terminal.ssh_password' => 'wrong-config-password']);

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'ssh_username' => 'stale-session-user',
            'ssh_port' => 22,
        ], [
            'provisioning' => 'template-clone',
            'ssh_username' => 'metadata-user',
            'ssh_password' => 'metadata-secret',
            'ssh_port' => 2222,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                $summary = $this->safeConnectionSummary($terminalSession);

                if (
                    $summary['credential_source'] !== 'vm_metadata_password'
                    || $summary['username'] !== 'metadata-user'
                    || $summary['port'] !== 2222
                ) {
                    return new SshCommandResult(
                        successful: false,
                        exitCode: null,
                        durationMs: 1,
                        output: '',
                        error: 'SSH authentication failed.',
                    );
                }

                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 12,
                    output: "metadata-user\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami');

        $this->assertSame('output', $result->type);
        $this->assertSame(CommandLogStatus::Succeeded->value, $result->status);
        $this->assertStringContainsString('metadata-user', $result->output);
        $this->assertStringNotContainsString('metadata-secret', $result->output);
        $this->assertStringNotContainsString('wrong-config-password', $result->output);
    }

    public function test_revoked_session_cannot_run_websocket_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => now(),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for revoked websocket sessions.');
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'whoami');

        $this->assertSame('blocked', $result->type);
        $this->assertSame(CommandLogStatus::Blocked->value, $result->status);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    public function test_expired_session_cannot_run_websocket_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for expired websocket sessions.');
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'uptime');

        $terminalSession->refresh();

        $this->assertSame('blocked', $result->type);
        $this->assertSame(TerminalSessionStatus::Expired, $terminalSession->status);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'command' => 'uptime',
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => 'student',
        ]);
    }

    private function terminalSessionFor(User $user, array $sessionAttributes = [], array $vmMetadata = []): TerminalSession
    {
        $vm = Vm::create([
            'user_id' => $user->id,
            'name' => 'ubuntu-student-01',
            'proxmox_id' => '101-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [
                'ssh_host' => '127.0.0.1',
                'ssh_port' => 22,
                'ssh_username' => 'student',
                ...$vmMetadata,
            ],
        ]);

        return TerminalSession::create([
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => 101,
            'ssh_host' => '127.0.0.1',
            'ssh_port' => 22,
            'ssh_username' => 'student',
            'status' => TerminalSessionStatus::Active,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
            ...$sessionAttributes,
        ]);
    }

    private function sharedPracticalTerminalSessionFor(User $user, array $sessionAttributes = [], bool $grantAccess = true, array $vmMetadata = []): TerminalSession
    {
        $vm = Vm::create([
            'user_id' => null,
            'name' => 'shared-practical-ubuntu',
            'proxmox_id' => 'shared-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [
                'shared_practical' => true,
                'ssh_host' => '127.0.0.1',
                'ssh_port' => 22,
                'ssh_username' => 'student',
                ...$vmMetadata,
            ],
        ]);

        if ($grantAccess) {
            $vm->practicalAccesses()->create([
                'user_id' => $user->id,
            ]);
        }

        return TerminalSession::create([
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => 2601,
            'ssh_host' => '127.0.0.1',
            'ssh_port' => 22,
            'ssh_username' => 'student',
            'status' => TerminalSessionStatus::Active,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
            ...$sessionAttributes,
        ]);
    }
}
