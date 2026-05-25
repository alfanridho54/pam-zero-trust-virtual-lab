<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\SshCommandResult;
use App\Services\SshCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalCommandExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_run_default_terminal_command_for_own_session(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [], [
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 25,
                    output: "student\nubuntu-student-01\nup 5 minutes\n",
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();

        $this->assertTrue($terminalSession->isActive());
        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'whoami && hostname && uptime',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
        ]);
    }

    public function test_blocked_terminal_command_is_logged_without_execution(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [], [
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for blocked commands.');
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'reboot',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'reboot',
            'status' => CommandLogStatus::Blocked->value,
            'blocked_reason' => 'Command diblokir oleh policy terminal.',
        ]);

        $terminalSession->refresh();

        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
    }

    public function test_protected_vm_terminal_command_is_blocked(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, ['system_vm' => true]);

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'status' => CommandLogStatus::Blocked->value,
            'blocked_reason' => 'Command diblokir untuk VM system atau protected.',
        ]);
    }

    public function test_expired_terminal_session_command_is_blocked(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [], [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('error');

        $terminalSession->refresh();

        $this->assertSame(TerminalSessionStatus::Expired, $terminalSession->status);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    public function test_closed_and_revoked_sessions_do_not_execute_commands(): void
    {
        $user = $this->student();
        $closedSession = $this->terminalSessionFor($user, [], [
            'status' => TerminalSessionStatus::Closed,
            'ended_at' => now(),
        ]);
        $revokedSession = $this->terminalSessionFor($user, [], [
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => now(),
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for ended sessions.');
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $closedSession))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $revokedSession))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $closedSession->id,
            'status' => CommandLogStatus::Blocked->value,
        ]);
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $revokedSession->id,
            'status' => CommandLogStatus::Blocked->value,
        ]);
    }

    public function test_show_page_expires_past_due_session_and_warns_when_near_expiry(): void
    {
        $user = $this->student();
        $expiredSession = $this->terminalSessionFor($user, [], [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);
        $warningSession = $this->terminalSessionFor($user, [], [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->addMinutes(4),
        ]);

        $this->actingAs($user)
            ->get(route('terminal-sessions.show', $expiredSession))
            ->assertOk()
            ->assertSee('expired');

        $expiredSession->refresh();

        $this->assertSame(TerminalSessionStatus::Expired, $expiredSession->status);

        $this->actingAs($user)
            ->get(route('terminal-sessions.show', $warningSession))
            ->assertOk()
            ->assertSee('Session expires in less than 5 minutes.');
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => 'student',
        ]);
    }

    private function terminalSessionFor(User $user, array $vmMetadata = [], array $sessionAttributes = []): TerminalSession
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
            'status' => TerminalSessionStatus::Pending,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
            ...$sessionAttributes,
        ]);
    }
}
