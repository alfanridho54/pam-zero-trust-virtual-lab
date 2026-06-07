<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\Vm;
use App\Models\VmTemplate;
use App\Services\ProxmoxService;
use App\Services\SshCommandResult;
use App\Services\SshCommandService;
use App\Services\SshReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedVmAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_and_unassign_existing_vm_to_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->vm(['user_id' => null, 'name' => 'Admin Practical VM']);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.assignment.store', $vm), [
                'student_id' => $student->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();

        $this->assertSame($student->id, $vm->user_id);
        $this->assertTrue($vm->metadata['managed_assignment']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'vm_id' => $vm->id,
            'action' => 'dashboard.vm.assigned',
        ]);

        $this->actingAs($admin)
            ->delete(route('dashboard.vms.assignment.destroy', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull($vm->refresh()->user_id);
        $this->assertFalse($vm->metadata['managed_assignment']);
    }

    public function test_admin_can_assign_existing_vm_to_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->vm(['user_id' => null]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.assignment.store', $vm), [
                'student_id' => $student->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame($student->id, $vm->refresh()->user_id);
    }

    public function test_assignment_requires_student_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'admin']);
        $vm = $this->vm(['user_id' => null]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.assignment.store', $vm), [
                'student_id' => $manager->id,
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertNull($vm->refresh()->user_id);
    }

    public function test_student_can_see_assigned_practical_vm(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $assignedVm = $this->vm(['user_id' => $student->id, 'name' => 'Assigned Practical VM']);
        $otherVm = $this->vm(['user_id' => $otherStudent->id, 'name' => 'Other Assigned VM']);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($assignedVm->name)
            ->assertDontSee($otherVm->name);
    }

    public function test_student_can_open_pam_terminal_for_assigned_vm(): void
    {
        config([
            'services.terminal.ssh_ready_attempts' => 1,
            'services.terminal.ssh_ready_delay_ms' => 0,
        ]);

        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                return true;
            }
        });

        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->vm([
            'user_id' => $student->id,
            'status' => 'running',
            'metadata' => [
                'managed_assignment' => true,
                'vmid' => 2610,
                'ssh_host' => '10.20.30.40',
                'ssh_port' => 22,
                'ssh_username' => 'student',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'vmid' => 2610,
            'ssh_host' => '10.20.30.40',
            'status' => TerminalSessionStatus::Pending->value,
        ]);
    }

    public function test_student_cannot_access_another_students_assigned_vm_terminal(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $vm = $this->vm([
            'user_id' => $otherStudent->id,
            'status' => 'running',
            'metadata' => [
                'managed_assignment' => true,
                'vmid' => 2611,
                'ssh_host' => '10.20.30.41',
            ],
        ]);

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertForbidden();

        $this->assertDatabaseMissing('terminal_sessions', [
            'vm_id' => $vm->id,
        ]);
    }

    public function test_system_critical_and_soft_deleted_vms_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $systemVm = $this->vm(['user_id' => null, 'proxmox_id' => '100', 'metadata' => ['vmid' => 100]]);
        $criticalVm = $this->vm(['user_id' => null, 'proxmox_id' => '101', 'metadata' => ['vmid' => 101]]);
        $deletedVm = $this->vm(['user_id' => null, 'proxmox_id' => '2612']);
        $deletedVm->delete();

        foreach ([$systemVm, $criticalVm, $deletedVm] as $vm) {
            $this->actingAs($admin)
                ->post(route('dashboard.vms.assignment.store', $vm), [
                    'student_id' => $student->id,
                ])
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertNull($vm->refresh()->user_id);
        }
    }

    public function test_self_service_vm_creation_still_works(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = VmTemplate::create([
            'name' => 'Regression Template',
            'description' => 'Template for assignment regression.',
            'proxmox_template_id' => 9101,
            'proxmox_node' => 'pve-mock',
            'cpu' => 1,
            'ram' => 1024,
            'disk' => 10,
            'ssh_username' => 'student',
            'enabled' => true,
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct() {}

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'proxmox_id' => '2701',
                    'node' => 'pve-mock',
                    'vmid' => 2701,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'task_upid' => null,
                    'local_status' => 'stopped',
                ];
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Self Service Still Works',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'user_id' => $student->id,
            'name' => 'Self Service Still Works',
            'proxmox_id' => '2701',
        ]);
    }

    public function test_admin_can_bulk_generate_managed_vms_for_multiple_students(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $students = User::factory()->count(2)->create(['role' => 'student']);
        $sourceVm = $this->vm([
            'user_id' => null,
            'name' => 'Linux Practical Template',
            'proxmox_id' => '2800',
            'node' => 'pve-template',
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
            'metadata' => [
                'vmid' => 2800,
                'ssh_username' => 'student',
                'ssh_password' => 'template-secret',
            ],
        ]);

        $this->fakeManagedCloneService();
        $this->fakeSshReadiness();

        $this->actingAs($admin)
            ->post(route('dashboard.vms.bulk-managed-generation.store'), [
                'source_vm_id' => $sourceVm->id,
                'target_mode' => 'selected',
                'student_ids' => $students->pluck('id')->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ($students as $student) {
            $vm = Vm::where('user_id', $student->id)
                ->where('metadata->managed_assignment', true)
                ->firstOrFail();

            $this->assertStringStartsWith('practical-', $vm->name);
            $this->assertSame(2800, $vm->metadata['source_template_vmid']);
            $this->assertSame('managed-template-clone', $vm->metadata['provisioning']);
            $this->assertSame('student', $vm->metadata['ssh_username']);
            $this->assertSame('template-secret', $vm->metadata['ssh_password']);
            $this->assertSame(2, $vm->cpu_cores);
            $this->assertSame(2048, $vm->memory_mb);
        }

        $firstStudentVm = Vm::where('user_id', $students->first()->id)
            ->where('metadata->managed_assignment', true)
            ->firstOrFail();

        $this->actingAs($students->first())
            ->post(route('terminal-sessions.store', $firstStudentVm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $students->first()->id,
            'vm_id' => $firstStudentVm->id,
            'status' => TerminalSessionStatus::Pending->value,
        ]);
    }

    public function test_admin_can_bulk_generate_managed_vms_for_all_students(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $students = User::factory()->count(3)->create(['role' => 'student']);
        $sourceVm = $this->vm([
            'user_id' => null,
            'proxmox_id' => '2801',
            'metadata' => ['vmid' => 2801],
        ]);

        $this->fakeManagedCloneService();

        $this->actingAs($admin)
            ->post(route('dashboard.vms.bulk-managed-generation.store'), [
                'source_vm_id' => $sourceVm->id,
                'target_mode' => 'all',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ($students as $student) {
            $this->assertDatabaseHas('vms', [
                'user_id' => $student->id,
            ]);
        }

        $this->assertSame($students->count(), Vm::query()
            ->get()
            ->filter(fn (Vm $vm) => $vm->isManagedAssignment() && ($vm->metadata['source_template_vmid'] ?? null) === 2801)
            ->count());
    }

    public function test_bulk_generation_prevents_duplicate_managed_vm_for_same_student_template_without_confirmation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $sourceVm = $this->vm([
            'user_id' => null,
            'proxmox_id' => '2802',
            'metadata' => ['vmid' => 2802],
        ]);
        $this->vm([
            'user_id' => $student->id,
            'proxmox_id' => '2902',
            'metadata' => [
                'managed_assignment' => true,
                'source_template_vmid' => 2802,
            ],
        ]);

        $this->fakeManagedCloneService();

        $this->actingAs($admin)
            ->post(route('dashboard.vms.bulk-managed-generation.store'), [
                'source_vm_id' => $sourceVm->id,
                'target_mode' => 'selected',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Vm::where('user_id', $student->id)->get()->filter(fn (Vm $vm) => $vm->isManagedAssignment())->count());
    }

    public function test_bulk_generation_blocks_protected_source_without_usable_template_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $sourceVm = $this->vm([
            'user_id' => null,
            'proxmox_id' => '101',
            'metadata' => ['vmid' => 101],
        ]);

        $this->fakeManagedCloneService();

        $this->actingAs($admin)
            ->post(route('dashboard.vms.bulk-managed-generation.store'), [
                'source_vm_id' => $sourceVm->id,
                'target_mode' => 'selected',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('vms', [
            'user_id' => $student->id,
            'metadata->managed_assignment' => true,
        ]);
    }

    public function test_managed_vm_does_not_count_toward_self_service_quota(): void
    {
        config(['lab.max_student_vms' => 1]);

        $student = User::factory()->create(['role' => 'student']);
        UserQuota::create([
            'user_id' => $student->id,
            'max_vms' => 1,
            'max_cpu_cores' => 4,
            'max_memory_mb' => 4096,
            'max_disk_gb' => 40,
        ]);
        $this->vm([
            'user_id' => $student->id,
            'name' => 'Managed Quota Free VM',
            'metadata' => [
                'managed_assignment' => true,
                'source_template_vmid' => 2803,
                'provisioning' => 'managed-template-clone',
            ],
        ]);
        $template = $this->selfServiceTemplate();

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct() {}

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'proxmox_id' => '3001',
                    'node' => 'pve-mock',
                    'vmid' => 3001,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'task_upid' => null,
                    'local_status' => 'stopped',
                ];
            }
        });

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee('Managed Quota Free VM')
            ->assertSee('0 of 1 VM quota used');

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Quota Allowed Self Service',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'user_id' => $student->id,
            'name' => 'Quota Allowed Self Service',
        ]);
    }

    public function test_self_service_quota_still_blocks_non_managed_vms(): void
    {
        config(['lab.max_student_vms' => 1]);

        $student = User::factory()->create(['role' => 'student']);
        $this->vm(['user_id' => $student->id, 'name' => 'Existing Self Service VM']);
        $template = $this->selfServiceTemplate();

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Blocked Self Service VM',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('vms', [
            'user_id' => $student->id,
            'name' => 'Blocked Self Service VM',
        ]);
    }

    public function test_admin_can_mark_vm_as_shared_practical(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vm = $this->vm(['user_id' => null]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.shared-practical.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($vm->refresh()->isSharedPractical());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'vm_id' => $vm->id,
            'action' => 'dashboard.vm.shared_practical.marked',
        ]);
    }

    public function test_admin_can_grant_shared_access_to_one_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->vm(['user_id' => null]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.practical-accesses.store', $vm), [
                'target_mode' => 'selected',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($vm->refresh()->isSharedPractical());
        $this->assertDatabaseHas('practical_vm_accesses', [
            'vm_id' => $vm->id,
            'user_id' => $student->id,
            'assigned_by' => $admin->id,
        ]);
    }

    public function test_admin_can_grant_shared_access_to_all_students(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $students = User::factory()->count(3)->create(['role' => 'student']);
        $vm = $this->vm(['user_id' => null]);

        $this->actingAs($admin)
            ->post(route('dashboard.vms.practical-accesses.store', $vm), [
                'target_mode' => 'all',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ($students as $student) {
            $this->assertDatabaseHas('practical_vm_accesses', [
                'vm_id' => $vm->id,
                'user_id' => $student->id,
                'assigned_by' => $admin->id,
            ]);
        }
    }

    public function test_student_with_shared_access_can_see_shared_vm(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin, ['name' => 'Shared Linux Practice']);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($vm->name);

        $this->actingAs($otherStudent)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertDontSee($vm->name);
    }

    public function test_deleted_vm_with_same_proxmox_vmid_does_not_override_active_shared_vm_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $deletedVm = $this->vm([
            'user_id' => null,
            'name' => 'Deleted Historical Shared VM',
            'proxmox_id' => 'deleted-3301',
            'node' => 'pve-test',
            'metadata' => ['shared_practical' => true, 'vmid' => 3301],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $deletedVm->delete();

        $activeVm = $this->sharedVmFor($student, $admin, [
            'name' => 'Current Shared Practice VM',
            'proxmox_id' => 'active-3301',
            'node' => 'pve-test',
            'status' => 'running',
            'metadata' => ['shared_practical' => true, 'vmid' => 3301],
        ]);

        $this->fakeProxmoxInventory([
            ['vmid' => 3301, 'node' => 'pve-test', 'name' => 'Proxmox VM 3301', 'status' => 'stopped'],
        ]);

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($activeVm->name)
            ->assertDontSee($deletedVm->name);
    }

    public function test_shared_vm_status_shown_to_student_matches_proxmox_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin, [
            'name' => 'Shared Status VM',
            'proxmox_id' => '3302',
            'node' => 'pve-test',
            'status' => 'running',
            'metadata' => ['shared_practical' => true, 'vmid' => 3302],
        ]);

        $this->fakeProxmoxInventory([
            ['vmid' => 3302, 'node' => 'pve-test', 'name' => 'Shared Status VM', 'status' => 'stopped'],
        ]);

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($vm->name)
            ->assertSee('Your assigned VM is stopped');
    }

    public function test_stopped_shared_vm_appears_stopped_on_student_vm_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin, [
            'name' => 'Stopped Shared VM',
            'proxmox_id' => '3303',
            'node' => 'pve-test',
            'status' => 'running',
            'metadata' => ['shared_practical' => true, 'vmid' => 3303],
        ]);

        $this->fakeProxmoxInventory([
            ['vmid' => 3303, 'node' => 'pve-test', 'name' => 'Stopped Shared VM', 'status' => 'stopped'],
        ]);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($vm->name)
            ->assertSee('stopped');
    }

    public function test_shared_vm_status_is_unknown_when_proxmox_status_is_unavailable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin, [
            'name' => 'Unavailable Shared VM',
            'proxmox_id' => '3304',
            'node' => 'pve-test',
            'status' => 'running',
            'metadata' => ['shared_practical' => true, 'vmid' => 3304],
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct() {}

            public function listVms(): array
            {
                return ['success' => false, 'message' => 'Proxmox unavailable', 'data' => []];
            }
        });

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($vm->name)
            ->assertSee('unknown');
    }

    public function test_student_with_shared_access_can_open_terminal_and_logs_record_authenticated_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin, [
            'status' => 'running',
            'metadata' => [
                'shared_practical' => true,
                'vmid' => 3101,
                'ssh_host' => '10.30.40.50',
                'ssh_port' => 22,
                'ssh_username' => 'student',
            ],
        ]);

        $this->fakeSshReadiness();
        $this->app->instance(SshCommandService::class, new class extends SshCommandService
        {
            public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
            {
                return new SshCommandResult(
                    successful: true,
                    exitCode: 0,
                    durationMs: 20,
                    output: "student\nshared-practical\nup 1 minute\n",
                );
            }
        });

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $terminalSession = TerminalSession::where('user_id', $student->id)
            ->where('vm_id', $vm->id)
            ->firstOrFail();

        $this->actingAs($student)
            ->post(route('terminal-sessions.commands.store', $terminalSession), [
                'command' => 'whoami',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'status' => TerminalSessionStatus::Active->value,
        ]);

        $this->assertDatabaseHas('command_logs', [
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Succeeded->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'terminal.session.created',
        ]);
    }

    public function test_student_without_shared_access_cannot_see_or_open_shared_vm(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $allowedStudent = User::factory()->create(['role' => 'student']);
        $blockedStudent = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($allowedStudent, $admin, [
            'name' => 'Restricted Shared VM',
            'status' => 'running',
            'metadata' => [
                'shared_practical' => true,
                'vmid' => 3102,
                'ssh_host' => '10.30.40.51',
            ],
        ]);

        $this->actingAs($blockedStudent)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertDontSee($vm->name);

        $this->actingAs($blockedStudent)
            ->post(route('terminal-sessions.store', $vm))
            ->assertForbidden();
    }

    public function test_shared_vm_does_not_count_toward_self_service_quota(): void
    {
        config(['lab.max_student_vms' => 1]);

        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        UserQuota::create([
            'user_id' => $student->id,
            'max_vms' => 1,
            'max_cpu_cores' => 4,
            'max_memory_mb' => 4096,
            'max_disk_gb' => 40,
        ]);
        $sharedVm = $this->sharedVmFor($student, $admin, ['name' => 'Quota Free Shared VM']);
        $template = $this->selfServiceTemplate();

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct() {}

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'proxmox_id' => '3201',
                    'node' => 'pve-mock',
                    'vmid' => 3201,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'task_upid' => null,
                    'local_status' => 'stopped',
                ];
            }
        });

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($sharedVm->name)
            ->assertSee('0 of 1 VM quota used');

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Self Service Beside Shared',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'user_id' => $student->id,
            'name' => 'Self Service Beside Shared',
        ]);
    }

    public function test_admin_can_revoke_shared_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $vm = $this->sharedVmFor($student, $admin);

        $this->actingAs($admin)
            ->delete(route('dashboard.vms.practical-accesses.destroy', $vm), [
                'target_mode' => 'selected',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('practical_vm_accesses', [
            'vm_id' => $vm->id,
            'user_id' => $student->id,
        ]);
    }

    private function vm(array $attributes = []): Vm
    {
        return Vm::create([
            'user_id' => array_key_exists('user_id', $attributes)
                ? $attributes['user_id']
                : User::factory()->create(['role' => 'student'])->id,
            'name' => 'managed-practical-vm',
            'proxmox_id' => '2600-'.str()->random(8),
            'node' => 'pve-test',
            'status' => 'stopped',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => [],
            ...$attributes,
        ]);
    }

    private function selfServiceTemplate(): VmTemplate
    {
        return VmTemplate::create([
            'name' => 'Quota Regression Template '.str()->random(6),
            'description' => 'Template for quota tests.',
            'proxmox_template_id' => 9102,
            'proxmox_node' => 'pve-mock',
            'cpu' => 1,
            'ram' => 1024,
            'disk' => 10,
            'ssh_username' => 'student',
            'enabled' => true,
        ]);
    }

    private function sharedVmFor(User $student, User $assignedBy, array $attributes = []): Vm
    {
        $vm = $this->vm([
            'user_id' => null,
            'name' => 'shared-practical-vm',
            'metadata' => ['shared_practical' => true],
            ...$attributes,
        ]);

        $vm->practicalAccesses()->create([
            'user_id' => $student->id,
            'assigned_by' => $assignedBy->id,
        ]);

        return $vm->refresh();
    }

    private function fakeManagedCloneService(): void
    {
        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            private int $vmid = 2900;

            public function __construct() {}

            public function cloneManagedVmFromVm(Vm $templateVm, string $vmName): array
            {
                $this->vmid++;

                return [
                    'success' => true,
                    'proxmox_id' => (string) $this->vmid,
                    'node' => $templateVm->node,
                    'vmid' => $this->vmid,
                    'name' => $vmName,
                    'source_template_vmid' => $templateVm->proxmoxVmid(),
                    'task_upid' => 'UPID:pve-test:clone:'.$this->vmid,
                    'local_status' => 'running',
                    'ssh_host' => '10.20.30.'.($this->vmid - 2900),
                    'ssh_port' => 22,
                ];
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return '10.20.30.'.($vmid - 2900);
            }
        });
    }

    private function fakeSshReadiness(): void
    {
        $this->app->instance(SshReadinessService::class, new class extends SshReadinessService
        {
            public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
            {
                return true;
            }
        });
    }

    private function fakeProxmoxInventory(array $vms): void
    {
        $this->app->instance(ProxmoxService::class, new class($vms) extends ProxmoxService
        {
            public function __construct(private readonly array $vms) {}

            public function listVms(): array
            {
                return ['success' => true, 'message' => 'OK', 'data' => $this->vms];
            }
        });
    }
}
