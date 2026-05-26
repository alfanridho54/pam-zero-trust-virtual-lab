<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudentVmSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_see_only_own_vm_on_dashboard(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $otherStudent = User::where('email', 'siswa2@lab.test')->firstOrFail();
        $ownVm = $this->createVmForOwner($student, 'Siswa 1 Self Service VM', 2101);
        $otherVm = $this->createVmForOwner($otherStudent, 'Siswa 2 Hidden VM', 2201);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($ownVm->name)
            ->assertSee((string) $ownVm->proxmoxVmid())
            ->assertDontSee($otherVm->name)
            ->assertDontSee((string) $otherVm->proxmoxVmid());
    }

    public function test_student_cannot_control_another_students_vm(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $otherStudent = User::where('email', 'siswa2@lab.test')->firstOrFail();
        $otherVm = $this->createVmForOwner($otherStudent, 'Other Student VM', 2202);

        $this->actingAs($student)
            ->post(route('student.vms.action', [$otherVm, 'start']))
            ->assertForbidden();

        $this->assertDatabaseHas('vms', [
            'id' => $otherVm->id,
            'status' => 'stopped',
        ]);
    }

    public function test_student_cannot_control_critical_or_system_vm(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Student System VM', 101, [
            'critical' => true,
            'system_vm' => true,
        ]);

        $this->actingAs($student)
            ->post(route('student.vms.action', [$vm, 'start']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vms', [
            'id' => $vm->id,
            'status' => 'stopped',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.critical_blocked',
        ]);
    }

    public function test_infrastructure_vms_are_hidden_from_student_dashboard(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $systemVm = $this->createVmForOwner($student, 'Windows10 Infrastructure VM', 100);
        $templateVm = $this->createVmForOwner($student, 'Ubuntu Student Template', 102);
        $normalVm = $this->createVmForOwner($student, 'Visible Student VM', 2401);

        $systemVm->refresh();
        $templateVm->refresh();

        $this->assertTrue($systemVm->isSystemVm());
        $this->assertTrue($systemVm->isCritical());
        $this->assertTrue($templateVm->isSystemVm());
        $this->assertTrue($templateVm->isCritical());
        $this->assertTrue($systemVm->metadata['system_vm']);
        $this->assertTrue($systemVm->metadata['critical']);
        $this->assertTrue($templateVm->metadata['system_vm']);
        $this->assertTrue($templateVm->metadata['critical']);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($normalVm->name)
            ->assertDontSee($systemVm->name)
            ->assertDontSee($templateVm->name);
    }

    public function test_infrastructure_vms_are_excluded_from_student_quota(): void
    {
        $this->seed();
        config(['lab.max_student_vms' => 1]);

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $this->createVmForOwner($student, 'Ubuntu Student Template', 102);

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Allowed Normal VM',
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'user_id' => $student->id,
            'name' => 'Allowed Normal VM',
        ]);
    }

    public function test_student_cannot_control_vmid_100_or_102_even_without_manual_flags(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $windowsVm = $this->createVmForOwner($student, 'Windows10', 100);
        $templateVm = $this->createVmForOwner($student, 'Ubuntu Student Template', 102);

        $this->actingAs($student)
            ->post(route('student.vms.action', [$windowsVm, 'start']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($student)
            ->post(route('student.vms.action', [$templateVm, 'start']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vms', [
            'id' => $windowsVm->id,
            'status' => 'stopped',
        ]);

        $this->assertDatabaseHas('vms', [
            'id' => $templateVm->id,
            'status' => 'stopped',
        ]);
    }

    public function test_quota_blocks_extra_student_vm_creation(): void
    {
        $this->seed();
        config(['lab.max_student_vms' => 1]);

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $this->createVmForOwner($student, 'Existing Student VM', 2103);

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Extra Student VM',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('vms', [
            'user_id' => $student->id,
            'name' => 'Extra Student VM',
        ]);
    }

    public function test_lifecycle_actions_update_status_and_create_audit_logs(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Lifecycle Student VM', 2104);

        $this->actingAs($student)
            ->post(route('student.vms.action', [$vm, 'start']))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'id' => $vm->id,
            'status' => 'running',
        ]);

        $this->actingAs($student)
            ->post(route('student.vms.action', [$vm->refresh(), 'shutdown']))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('vms', [
            'id' => $vm->id,
            'status' => 'stopped',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.start.success',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.shutdown.success',
        ]);

        $this->assertSame(2, AuditLog::where('vm_id', $vm->id)->count());
    }

    public function test_student_can_create_vm_from_configured_template(): void
    {
        $this->seed();
        config([
            'lab.max_student_vms' => 2,
            'services.proxmox.student_template_vmid' => 9100,
        ]);

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Template Clone VM',
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $vm = Vm::where('user_id', $student->id)
            ->where('name', 'Template Clone VM')
            ->firstOrFail();

        $this->assertSame(9100, $vm->metadata['source_template_vmid']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.created',
        ]);
    }

    public function test_failed_template_clone_is_audited_without_saving_vm(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function cloneStudentVmFromTemplate(string $vmName): array
            {
                return [
                    'success' => false,
                    'status' => 403,
                    'message' => 'Permission check failed. Pastikan token Proxmox memiliki permission VM.Clone.',
                ];
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Denied Clone VM',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('vms', [
            'user_id' => $student->id,
            'name' => 'Denied Clone VM',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => null,
            'action' => 'student.vm.create.failed',
        ]);
    }

    public function test_local_save_failure_after_clone_is_audited_as_provisioning_mismatch(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $oldVm = $this->createVmForOwner($student, 'Old Deleted VM', 2501);
        $oldVm->delete();

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function cloneStudentVmFromTemplate(string $vmName): array
            {
                return [
                    'success' => true,
                    'status' => 200,
                    'message' => 'OK',
                    'data' => 'UPID:pve1:clone:2501:',
                    'proxmox_id' => '2501',
                    'node' => 'pve1',
                    'vmid' => 2501,
                    'name' => $vmName,
                    'source_template_vmid' => 102,
                    'task_upid' => 'UPID:pve1:clone:2501:',
                    'local_status' => 'provisioning',
                ];
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Duplicate Local Save VM',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('vms', [
            'user_id' => $student->id,
            'name' => 'Duplicate Local Save VM',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => null,
            'action' => 'student.vm.provisioning_mismatch',
        ]);
    }

    public function test_student_delete_accepts_empty_non_json_proxmox_delete_response(): void
    {
        $this->seed();
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve-mock/qemu/2301?purge=1' => Http::response('', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Delete Empty Response VM', 2301);

        $this->actingAs($student)
            ->delete(route('student.vms.destroy', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSoftDeleted('vms', ['id' => $vm->id]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.deleted.success',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://proxmox.test:8006/api2/json/nodes/pve-mock/qemu/2301?purge=1'
            && $request->body() === '');
    }

    public function test_failed_student_delete_does_not_remove_local_vm_record(): void
    {
        $this->seed();
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve-mock/qemu/2302?purge=1' => Http::response('delete rejected', 500, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Delete Failed VM', 2302);

        $this->actingAs($student)
            ->delete(route('student.vms.destroy', $vm))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vms', [
            'id' => $vm->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.deleted.failed',
        ]);
    }

    private function createVmForOwner(User $owner, string $name, int $vmid, array $metadata = []): Vm
    {
        return $owner->vms()->create([
            'name' => $name,
            'proxmox_id' => (string) $vmid,
            'node' => 'pve-mock',
            'status' => 'stopped',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => $metadata + ['vmid' => $vmid],
        ]);
    }
}
