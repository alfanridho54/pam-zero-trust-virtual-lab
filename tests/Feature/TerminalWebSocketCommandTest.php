<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Console\Commands\TerminalWebSocketServer;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use App\Services\SshCommandResult;
use App\Services\SshCommandService;
use App\Services\TerminalPtySessionService;
use App\Services\TerminalWebSocketCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use ReflectionMethod;
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

    public function test_websocket_command_with_empty_stdout_and_zero_exit_is_logged_as_succeeded(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 9,
                    output: '',
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'touch usr');

        $this->assertSame('output', $result->type);
        $this->assertSame(CommandLogStatus::Succeeded->value, $result->status);
        $this->assertSame('(command completed with no output)', $result->output);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'touch usr',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
            'output_excerpt' => '(command completed with no output)',
        ]);
    }

    public function test_websocket_command_with_output_is_logged_as_succeeded(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 8,
                    output: "test\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'find test');

        $this->assertSame('output', $result->type);
        $this->assertSame(CommandLogStatus::Succeeded->value, $result->status);
        $this->assertSame("test\n", $result->output);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'find test',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
            'output_excerpt' => "test\n",
        ]);
    }

    public function test_websocket_command_with_non_zero_exit_status_is_logged_as_failed(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: false,
                    exitCode: 1,
                    durationMs: 13,
                    output: '',
                    stderr: "ping: connect: Network is unreachable\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'ping -c 4 8.8.8.8');

        $this->assertSame('failed', $result->type);
        $this->assertSame(CommandLogStatus::Failed->value, $result->status);
        $this->assertSame("ping: connect: Network is unreachable\n", $result->output);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'ping -c 4 8.8.8.8',
            'status' => CommandLogStatus::Failed->value,
            'exit_code' => 1,
            'output_excerpt' => "ping: connect: Network is unreachable\n",
        ]);
    }

    public function test_pending_websocket_session_becomes_active_after_first_allowed_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, [
            'status' => TerminalSessionStatus::Pending,
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
                    output: "/home/student01\n",
                );
            }
        });

        $result = $this->app->make(TerminalWebSocketCommandService::class)
            ->run($terminalSession, $user, 'pwd');

        $this->assertSame('output', $result->type);
        $this->assertSame(TerminalSessionStatus::Active->value, $result->sessionStatus);
        $this->assertSame(TerminalSessionStatus::Active, $terminalSession->refresh()->status);
        $this->assertSame(TerminalSessionStatus::Active->value, $result->toPayload()['session_status']);
    }

    public function test_self_service_vm_is_eligible_for_pty_terminal_mode(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, vmMetadata: [
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake-test-key\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        $this->assertTrue($this->app->make(TerminalPtySessionService::class)->canOpen($terminalSession, $user));
    }

    public function test_shared_practical_vm_remains_command_mode_only(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->assertFalse($this->app->make(TerminalPtySessionService::class)->canOpen($terminalSession, $user));
    }

    public function test_shared_practical_vm_with_active_temporary_credential_can_use_pty_mode(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_2601_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);

        $this->assertTrue($this->app->make(TerminalPtySessionService::class)->canOpen($terminalSession, $user));
    }

    public function test_unauthorized_student_cannot_open_pty_for_another_users_vm(): void
    {
        $owner = $this->student();
        $otherStudent = $this->student();
        $terminalSession = $this->terminalSessionFor($owner);

        $this->assertFalse($this->app->make(TerminalPtySessionService::class)->canOpen($terminalSession, $otherStudent));
    }

    public function test_revoked_and_expired_sessions_cannot_open_pty_mode(): void
    {
        $user = $this->student();
        $revokedSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => now(),
        ]);
        $expiredSession = $this->terminalSessionFor($user, [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);
        $ptyService = $this->app->make(TerminalPtySessionService::class);

        $this->assertFalse($ptyService->canOpen($revokedSession, $user));
        $this->assertFalse($ptyService->canOpen($expiredSession, $user));
        $this->assertSame(TerminalSessionStatus::Expired, $expiredSession->refresh()->status);
    }

    public function test_websocket_receive_rejects_short_frame_without_unsafe_offsets(): void
    {
        if (! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('stream_socket_pair is not available in this PHP build.');
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            $this->markTestSkipped('Unable to create local socket pair.');
        }

        [$client, $server] = $sockets;
        fwrite($client, chr(129));
        fclose($client);

        $receive = new ReflectionMethod(TerminalWebSocketServer::class, 'receive');
        $receive->setAccessible(true);

        $this->assertNull($receive->invoke($this->app->make(TerminalWebSocketServer::class), $server));

        fclose($server);
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
