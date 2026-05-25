<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
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

    private function terminalSessionFor(User $user, array $sessionAttributes = []): TerminalSession
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
}
