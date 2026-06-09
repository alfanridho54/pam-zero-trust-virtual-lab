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
use App\Services\TemporaryVmCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
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

    public function test_stale_ip_gets_refreshed_before_terminal_command(): void
    {
        Log::spy();

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'provisioning' => 'student-self-service',
            'vmid' => 103,
            'ssh_host' => '172.16.1.30',
            'ip_address' => '172.16.1.30',
            'ssh_password' => 'metadata-secret',
        ], [
            'vmid' => 103,
            'ssh_host' => '172.16.1.30',
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve-mock' && $vmid === 103 ? '172.16.1.29' : null;
            }
        });

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: $terminalSession->ssh_host === '172.16.1.29',
                    exitCode: $terminalSession->ssh_host === '172.16.1.29' ? 0 : 1,
                    durationMs: 15,
                    output: $terminalSession->ssh_host,
                    error: $terminalSession->ssh_host === '172.16.1.29' ? '' : 'SSH used stale host '.$terminalSession->ssh_host,
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'hostname',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();
        $terminalSession->vm->refresh();

        $this->assertSame('172.16.1.29', $terminalSession->ssh_host);
        $this->assertSame('172.16.1.29', $terminalSession->vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.29', $terminalSession->vm->metadata['ip_address']);

        Log::shouldHaveReceived('info')
            ->with('VM SSH host refreshed from Proxmox guest agent.', \Mockery::on(fn (array $context): bool => $context === [
                'vm_id' => $terminalSession->vm_id,
                'proxmox_vmid' => 103,
                'old_ip' => '172.16.1.30',
                'new_ip' => '172.16.1.29',
            ]))
            ->once();
    }

    public function test_guest_agent_unavailable_falls_back_to_existing_metadata_before_command(): void
    {
        Log::spy();

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'provisioning' => 'student-self-service',
            'vmid' => 104,
            'ssh_host' => '172.16.1.30',
            'ip_address' => '172.16.1.30',
            'ssh_password' => 'metadata-secret',
        ], [
            'vmid' => 104,
            'ssh_host' => '172.16.1.30',
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

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: $terminalSession->ssh_host === '172.16.1.30',
                    exitCode: $terminalSession->ssh_host === '172.16.1.30' ? 0 : 1,
                    durationMs: 12,
                    output: $terminalSession->ssh_host,
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'hostname',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();
        $terminalSession->vm->refresh();

        $this->assertSame('172.16.1.30', $terminalSession->ssh_host);
        $this->assertSame('172.16.1.30', $terminalSession->vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.30', $terminalSession->vm->metadata['ip_address']);

        Log::shouldHaveReceived('warning')
            ->with('VM SSH host refresh unavailable; falling back to existing metadata.', \Mockery::on(fn (array $context): bool => ($context['vm_id'] ?? null) === $terminalSession->vm_id
                && ($context['proxmox_vmid'] ?? null) === 104
                && ($context['existing_ssh_host'] ?? null) === '172.16.1.30'))
            ->once();
    }

    public function test_self_service_vm_terminal_creation_uses_refreshed_ip(): void
    {
        config([
            'services.terminal.ssh_ready_attempts' => 1,
            'services.terminal.ssh_ready_delay_ms' => 0,
        ]);

        $user = $this->student();
        $vm = Vm::create([
            'user_id' => $user->id,
            'name' => 'self-service-vm',
            'proxmox_id' => '105',
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [
                'provisioning' => 'student-self-service',
                'vmid' => 105,
                'ssh_host' => '172.16.1.30',
                'ip_address' => '172.16.1.30',
                'ssh_username' => 'student',
                'ssh_password' => 'metadata-secret',
            ],
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve-mock' && $vmid === 105 ? '172.16.1.29' : null;
            }
        });

        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                return $host === '172.16.1.29';
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'vmid' => 105,
            'ssh_host' => '172.16.1.29',
            'status' => TerminalSessionStatus::Pending->value,
        ]);

        $vm->refresh();

        $this->assertSame('172.16.1.29', $vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.29', $vm->metadata['ip_address']);
    }

    public function test_temporary_vm_username_is_generated_safely(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);
        $service = new TemporaryVmCredentialService(new SshCommandService());

        $username = $service->generateUsername($terminalSession);

        $this->assertMatchesRegularExpression('/^jit_'.$terminalSession->id.'_[0-9a-f]{4}$/', $username);
        $this->assertLessThanOrEqual(32, strlen($username));
    }

    public function test_shared_practical_terminal_session_creates_encrypted_temporary_credential(): void
    {
        $user = $this->student();
        $vm = Vm::create([
            'user_id' => null,
            'name' => 'shared-practical-ubuntu',
            'proxmox_id' => 'shared-jit-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [
                'shared_practical' => true,
                'vmid' => 2601,
                'ssh_host' => '127.0.0.1',
                'ssh_port' => 22,
                'ssh_username' => 'student',
                'ssh_password' => 'metadata-secret',
            ],
        ]);
        $vm->practicalAccesses()->create(['user_id' => $user->id]);

        $this->app->instance(TemporaryVmCredentialService::class, new class extends TemporaryVmCredentialService
        {
            public function __construct()
            {
            }

            public function createTemporaryUser(Vm $vm, TerminalSession $session): array
            {
                $password = 'plain-temporary-password';
                $session->mergeTemporaryCredentialMetadata([
                    'enabled' => true,
                    'username' => 'jit_'.$session->id.'_abcd',
                    'password_encrypted' => Crypt::encryptString($password),
                    'status' => 'active',
                    'created_at' => now()->toISOString(),
                ]);

                return [
                    'username' => 'jit_'.$session->id.'_abcd',
                    'password' => $password,
                ];
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession = TerminalSession::where('user_id', $user->id)
            ->where('vm_id', $vm->id)
            ->firstOrFail();
        $credential = $terminalSession->temporaryCredentialMetadata();

        $this->assertSame('active', $credential['status']);
        $this->assertSame('jit_'.$terminalSession->id.'_abcd', $credential['username']);
        $this->assertNotSame('plain-temporary-password', $credential['password_encrypted']);
        $this->assertSame('plain-temporary-password', Crypt::decryptString($credential['password_encrypted']));
        $this->assertStringNotContainsString('plain-temporary-password', json_encode($terminalSession->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_shared_practical_access_is_enforced_before_temporary_user_creation(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, grantAccess: false);
        $vm = $terminalSession->vm;
        $called = new class
        {
            public bool $value = false;
        };

        $this->app->instance(TemporaryVmCredentialService::class, new class($called) extends TemporaryVmCredentialService
        {
            public function __construct(private object $called)
            {
            }

            public function createTemporaryUser(Vm $vm, TerminalSession $session): array
            {
                $this->called->value = true;

                return [];
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.store', $vm))
            ->assertForbidden();

        $this->assertFalse($called->value);
    }

    public function test_student_with_shared_practical_access_can_execute_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 18,
                    output: "student\nshared-practical\n",
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'whoami',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();

        $this->assertTrue($terminalSession->isActive());
        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'vm_id' => $terminalSession->vm_id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
        ]);
    }

    public function test_shared_practical_terminal_ui_form_endpoint_executes_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 21,
                    output: "student\nshared-practical-ui\n",
                );
            }
        });

        $this->actingAs($user)
            ->get(route('terminal-sessions.show', $terminalSession))
            ->assertOk()
            ->assertSee(route('terminal-sessions.commands.store', $terminalSession), false);

        $this->actingAs($user)
            ->from(route('terminal-sessions.show', $terminalSession))
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'hostname',
            ])
            ->assertRedirect(route('terminal-sessions.show', $terminalSession))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'vm_id' => $terminalSession->vm_id,
            'command' => 'hostname',
            'status' => CommandLogStatus::Succeeded->value,
        ]);
    }

    public function test_shared_practical_command_with_empty_stdout_and_zero_exit_is_logged_as_succeeded(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 14,
                    output: '',
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'mkdir test',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'mkdir test',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
            'output_excerpt' => '(command completed with no output)',
        ]);
    }

    public function test_shared_practical_command_with_output_is_logged_as_succeeded(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 10,
                    output: "test\n",
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'find test',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'find test',
            'status' => CommandLogStatus::Succeeded->value,
            'exit_code' => 0,
            'output_excerpt' => "test\n",
        ]);
    }

    public function test_shared_practical_command_with_non_zero_exit_status_is_logged_as_failed(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: false,
                    exitCode: 2,
                    durationMs: 11,
                    output: '',
                    stderr: "ls: cannot access 'missing': No such file or directory\n",
                );
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'ls missing',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'ls missing',
            'status' => CommandLogStatus::Failed->value,
            'exit_code' => 2,
            'output_excerpt' => "ls: cannot access 'missing': No such file or directory\n",
        ]);
    }

    public function test_student_without_shared_practical_access_cannot_execute_command(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, grantAccess: false);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not run without shared practical access.');
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'whoami',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('command_logs', [
            'terminal_session_id' => $terminalSession->id,
        ]);
    }

    public function test_student_cannot_execute_command_in_another_users_terminal_session(): void
    {
        $owner = $this->student();
        $otherStudent = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($owner);
        $terminalSession->vm->practicalAccesses()->create([
            'user_id' => $otherStudent->id,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not run for another user session.');
            }
        });

        $this->actingAs($otherStudent)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'whoami',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('command_logs', [
            'terminal_session_id' => $terminalSession->id,
        ]);
    }

    public function test_dynamic_vm_ssh_command_credentials_prefer_vm_metadata_password(): void
    {
        config(['services.terminal.ssh_password' => 'wrong-config-password']);

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'provisioning' => 'template-clone',
            'ssh_username' => 'metadata-user',
            'ssh_password' => 'metadata-secret',
            'ssh_port' => 2222,
        ], [
            'ssh_username' => 'session-user',
            'ssh_port' => 22,
        ]);

        $credentials = $this->credentialInspector()->inspect($terminalSession->load('vm'));

        $this->assertSame('metadata-user', $credentials['username']);
        $this->assertSame('metadata-secret', $credentials['password']);
        $this->assertSame(2222, $credentials['port']);
        $this->assertSame('vm_metadata_password', $credentials['source']);
    }

    public function test_static_ssh_command_credentials_use_config_fallback(): void
    {
        config(['services.terminal.ssh_password' => 'config-secret']);

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'ssh_username' => 'student',
        ]);

        $credentials = $this->credentialInspector()->inspect($terminalSession->load('vm'));

        $this->assertSame('student', $credentials['username']);
        $this->assertSame('config-secret', $credentials['password']);
        $this->assertSame(22, $credentials['port']);
        $this->assertSame('config_fallback', $credentials['source']);
    }

    public function test_ssh_command_credential_source_logging_never_includes_secrets(): void
    {
        Log::spy();

        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user, [
            'provisioning' => 'template-clone',
            'ssh_username' => 'metadata-user',
            'ssh_password' => 'metadata-secret',
            'ssh_port' => 1,
        ], [
            'ssh_host' => '127.0.0.1',
            'ssh_port' => 1,
            'ssh_username' => 'session-user',
        ]);

        app(SshCommandService::class)->execute($terminalSession, 'whoami', 1);

        Log::shouldHaveReceived('debug')
            ->with('SSH credential source resolved.', \Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return ($context['credential_source'] ?? null) === 'vm_metadata_password'
                    && ! str_contains($encoded, 'metadata-secret')
                    && ! str_contains($encoded, 'wrong-config-password');
            }))
            ->once();

        Log::shouldHaveReceived('warning')
            ->with('SSH command failed.', \Mockery::on(function (array $context) use ($terminalSession): bool {
                $encoded = json_encode($context);

                return ($context['host'] ?? null) === '127.0.0.1'
                    && ($context['username'] ?? null) === 'metadata-user'
                    && ($context['port'] ?? null) === 1
                    && ($context['session_id'] ?? null) === $terminalSession->id
                    && ($context['vm_id'] ?? null) === $terminalSession->vm_id
                    && array_key_exists('exception_class', $context)
                    && array_key_exists('exception_message', $context)
                    && array_key_exists('exit_code', $context)
                    && ! str_contains($encoded, 'metadata-secret')
                    && ! str_contains($encoded, 'wrong-config-password');
            }))
            ->once();
    }

    public function test_terminal_test_ssh_command_prints_safe_fields_and_runs_whoami(): void
    {
        $user = $this->student();
        $vm = Vm::create([
            'user_id' => $user->id,
            'name' => 'diagnostic-vm',
            'proxmox_id' => '4242',
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [
                'provisioning' => 'template-clone',
                'vmid' => 4242,
                'ssh_host' => '172.16.1.24',
                'ssh_port' => 2222,
                'ssh_username' => 'labuser',
                'ssh_password' => 'metadata-secret',
            ],
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 10,
                    output: "labuser\n",
                );
            }
        });

        $this->artisan('terminal:test-ssh', ['proxmox_vmid' => $vm->proxmox_id])
            ->expectsOutput('host: 172.16.1.24')
            ->expectsOutput('port: 2222')
            ->expectsOutput('username: labuser')
            ->expectsOutput('credential_source: vm_metadata_password')
            ->expectsOutput('auth: success')
            ->expectsOutput('whoami: labuser')
            ->doesntExpectOutput('metadata-secret')
            ->assertExitCode(0);
    }

    public function test_extreme_destructive_terminal_command_is_logged_without_execution(): void
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
                'command' => 'mkfs.ext4 /dev/sda',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'command' => 'mkfs.ext4 /dev/sda',
            'status' => CommandLogStatus::Blocked->value,
            'blocked_reason' => 'Command diblokir oleh policy terminal.',
        ]);

        $terminalSession->refresh();

        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
    }

    public function test_shared_practical_vm_blocks_reboot_and_shutdown_commands(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for blocked shared practical commands.');
            }
        });

        foreach (['reboot', 'shutdown now'] as $command) {
            $this->actingAs($user)
                ->post(route('terminal-sessions.commands.store', $terminalSession), [
                    'command' => $command,
                ])
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertDatabaseHas('command_logs', [
                'terminal_session_id' => $terminalSession->id,
                'user_id' => $user->id,
                'command' => $command,
                'status' => CommandLogStatus::Blocked->value,
                'blocked_reason' => 'Command diblokir oleh policy terminal.',
            ]);
        }
    }

    public function test_self_service_vm_allows_reboot_and_shutdown_commands_and_logs_them(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 20,
                    output: "accepted: {$command}\n",
                );
            }
        });

        foreach (['reboot', 'shutdown now'] as $command) {
            $this->actingAs($user)
                ->post(route('terminal-sessions.commands.store', $terminalSession), [
                    'command' => $command,
                ])
                ->assertRedirect()
                ->assertSessionHas('status');

            $this->assertDatabaseHas('command_logs', [
                'terminal_session_id' => $terminalSession->id,
                'user_id' => $user->id,
                'command' => $command,
                'status' => CommandLogStatus::Succeeded->value,
                'exit_code' => 0,
            ]);
        }
    }

    public function test_self_service_vm_still_blocks_extreme_destructive_commands(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for extreme destructive commands.');
            }
        });

        foreach (['rm -rf /', 'chmod -R 777 /'] as $command) {
            $this->actingAs($user)
                ->post(route('terminal-sessions.commands.store', $terminalSession), [
                    'command' => $command,
                ])
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertDatabaseHas('command_logs', [
                'terminal_session_id' => $terminalSession->id,
                'user_id' => $user->id,
                'command' => $command,
                'status' => CommandLogStatus::Blocked->value,
                'blocked_reason' => 'Command diblokir oleh policy terminal.',
            ]);
        }
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

    public function test_admin_can_revoke_active_terminal_session(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $terminalSession = $this->terminalSessionFor($student, [], [
            'status' => TerminalSessionStatus::Active,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($admin)
            ->post(route('terminal-sessions.revoke', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();

        $this->assertSame(TerminalSessionStatus::Revoked, $terminalSession->status);
        $this->assertNotNull($terminalSession->ended_at);
        $this->assertTrue($terminalSession->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'vm_id' => $terminalSession->vm_id,
            'action' => 'terminal_session.revoked',
        ]);

        $auditLog = AuditLog::where('action', 'terminal_session.revoked')->firstOrFail();

        $this->assertSame($terminalSession->id, $auditLog->metadata['terminal_session_id']);
        $this->assertSame($student->id, $auditLog->metadata['target_user_id']);
    }

    public function test_student_cannot_revoke_terminal_session(): void
    {
        $student = $this->student();
        $terminalSession = $this->terminalSessionFor($student, [], [
            'status' => TerminalSessionStatus::Active,
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.revoke', $terminalSession))
            ->assertForbidden();

        $terminalSession->refresh();

        $this->assertSame(TerminalSessionStatus::Active, $terminalSession->status);
        $this->assertNull($terminalSession->ended_at);
    }

    public function test_revoked_session_after_admin_revoke_cannot_execute_commands(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $terminalSession = $this->terminalSessionFor($student, [], [
            'status' => TerminalSessionStatus::Active,
        ]);

        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                throw new \RuntimeException('SSH should not be called for revoked sessions.');
            }
        });

        $this->actingAs($admin)
            ->post(route('terminal-sessions.revoke', $terminalSession))
            ->assertRedirect();

        $this->actingAs($student)
            ->post(route('terminal-sessions.commands.store', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
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

    public function test_student_closing_terminal_session_returns_to_student_vm_page(): void
    {
        $user = $this->student();
        $terminalSession = $this->terminalSessionFor($user);

        $this->actingAs($user)
            ->delete(route('terminal-sessions.destroy', $terminalSession))
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $this->assertTrue($terminalSession->refresh()->isEnded());
    }

    public function test_temporary_vm_credential_cleanup_is_marked_pending_when_session_closes(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Active,
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_close_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('terminal-sessions.destroy', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('cleanup_pending', $terminalSession->refresh()->temporaryCredentialStatus());
    }

    public function test_admin_revoke_marks_temporary_credential_cleanup_pending_without_running_cleanup(): void
    {
        $admin = $this->admin();
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Active,
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_revoke_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);
        $calls = new class
        {
            public int $count = 0;
        };

        $this->app->instance(TemporaryVmCredentialService::class, new class($calls) extends TemporaryVmCredentialService
        {
            public function __construct(private object $calls)
            {
            }

            public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
            {
                $this->calls->count++;
            }
        });

        $this->actingAs($admin)
            ->post(route('terminal-sessions.revoke', $terminalSession))
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession->refresh();

        $this->assertSame(0, $calls->count);
        $this->assertSame(TerminalSessionStatus::Revoked, $terminalSession->status);
        $this->assertSame('cleanup_pending', $terminalSession->temporaryCredentialStatus());
    }

    public function test_temporary_vm_credential_cleanup_is_marked_pending_for_revoked_and_expired_sessions(): void
    {
        $user = $this->student();
        $revokedSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_revoke_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);
        $expiredSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'expires_at' => now()->subMinute(),
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_expire_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);

        $revokedSession->revoke();
        $expiredSession->expireIfPastDue();

        $this->assertSame('cleanup_pending', $revokedSession->refresh()->temporaryCredentialStatus());
        $this->assertSame('cleanup_pending', $expiredSession->refresh()->temporaryCredentialStatus());
    }

    public function test_temporary_vm_credential_cleanup_command_marks_credential_cleaned(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Closed,
            'ended_at' => now(),
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_clean_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'cleanup_pending',
                ],
            ],
        ]);

        $this->app->instance(TemporaryVmCredentialService::class, new class extends TemporaryVmCredentialService
        {
            public function __construct()
            {
            }

            public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
            {
                $session->mergeTemporaryCredentialMetadata([
                    'status' => 'cleaned',
                    'cleaned_at' => now()->toISOString(),
                    'last_error' => null,
                ]);
            }
        });

        $this->artisan('terminal:sessions:cleanup-temporary-users')
            ->expectsOutput('Expired sessions: 0')
            ->expectsOutput('Cleanup attempted: 1')
            ->expectsOutput('Cleanup completed: 1')
            ->assertExitCode(0);

        $this->assertSame('cleaned', $terminalSession->refresh()->temporaryCredentialStatus());
    }

    public function test_temporary_vm_credential_cleanup_command_handles_missing_linux_user_idempotently(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => now(),
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_missing_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'cleanup_failed',
                    'last_error' => 'previous cleanup attempt failed',
                ],
            ],
        ]);

        $this->app->instance(TemporaryVmCredentialService::class, new class extends TemporaryVmCredentialService
        {
            public function __construct()
            {
            }

            public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
            {
                $session->mergeTemporaryCredentialMetadata([
                    'status' => 'already_removed',
                    'cleaned_at' => now()->toISOString(),
                    'last_error' => null,
                ]);
            }
        });

        $this->artisan('terminal:sessions:cleanup-temporary-users')
            ->expectsOutput('Cleanup attempted: 1')
            ->assertExitCode(0);

        $this->assertSame('already_removed', $terminalSession->refresh()->temporaryCredentialStatus());
        $this->assertNull($terminalSession->temporaryCredentialMetadata()['last_error']);
    }

    public function test_temporary_vm_credential_cleanup_command_records_failure_without_ui_request(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => now(),
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_fail_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'cleanup_pending',
                ],
            ],
        ]);

        $this->app->instance(TemporaryVmCredentialService::class, new class extends TemporaryVmCredentialService
        {
            public function __construct()
            {
            }

            public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
            {
                throw new \RuntimeException('cleanup sudo failed');
            }
        });

        $this->artisan('terminal:sessions:cleanup-temporary-users')
            ->expectsOutput('Cleanup attempted: 1')
            ->expectsOutput('Cleanup failed: 1')
            ->assertExitCode(1);

        $this->assertSame('cleanup_failed', $terminalSession->refresh()->temporaryCredentialStatus());
        $this->assertSame('cleanup sudo failed', $terminalSession->temporaryCredentialMetadata()['last_error']);
    }

    public function test_temporary_vm_credential_cleanup_command_expires_sessions_and_cleans_credentials(): void
    {
        $user = $this->student();
        $terminalSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Active,
            'expires_at' => now()->subMinute(),
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_expired_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);

        $this->app->instance(TemporaryVmCredentialService::class, new class extends TemporaryVmCredentialService
        {
            public function __construct()
            {
            }

            public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
            {
                $session->mergeTemporaryCredentialMetadata([
                    'status' => 'cleaned',
                    'cleaned_at' => now()->toISOString(),
                ]);
            }
        });

        $this->artisan('terminal:sessions:cleanup-temporary-users')
            ->expectsOutput('Expired sessions: 1')
            ->expectsOutput('Cleanup attempted: 1')
            ->assertExitCode(0);

        $terminalSession->refresh();

        $this->assertSame(TerminalSessionStatus::Expired, $terminalSession->status);
        $this->assertSame('cleaned', $terminalSession->temporaryCredentialStatus());
    }

    public function test_opening_duplicate_shared_practical_terminal_marks_old_session_cleanup_pending(): void
    {
        $user = $this->student();
        $oldSession = $this->sharedPracticalTerminalSessionFor($user, sessionAttributes: [
            'status' => TerminalSessionStatus::Active,
            'metadata' => [
                'temporary_credential' => [
                    'username' => 'jit_old_abcd',
                    'password_encrypted' => Crypt::encryptString('temporary-secret'),
                    'status' => 'active',
                ],
            ],
        ]);
        $vm = $oldSession->vm;

        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                return true;
            }
        });

        $this->actingAs($user)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $oldSession->refresh();

        $this->assertSame(TerminalSessionStatus::Closed, $oldSession->status);
        $this->assertSame('cleanup_pending', $oldSession->temporaryCredentialStatus());
        $this->assertSame(1, TerminalSession::where('user_id', $user->id)->where('vm_id', $vm->id)->whereNull('ended_at')->count());
    }

    public function test_admin_closing_terminal_session_returns_to_admin_vm_page(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $terminalSession = $this->terminalSessionFor($student);

        $this->actingAs($admin)
            ->delete(route('terminal-sessions.destroy', $terminalSession))
            ->assertRedirect(route('dashboard.vms'))
            ->assertSessionHas('status');

        $this->assertTrue($terminalSession->refresh()->isEnded());
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => 'student',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
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

    private function sharedPracticalTerminalSessionFor(User $user, bool $grantAccess = true, array $sessionAttributes = []): TerminalSession
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
                'vmid' => 2601,
                'ssh_host' => '127.0.0.1',
                'ssh_port' => 22,
                'ssh_username' => 'student',
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
            'status' => TerminalSessionStatus::Pending,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
            ...$sessionAttributes,
        ]);
    }

    private function credentialInspector(): object
    {
        return new class extends SshCommandService
        {
            public function inspect(TerminalSession $terminalSession): array
            {
                return $this->resolveCredentials($terminalSession);
            }
        };
    }
}
