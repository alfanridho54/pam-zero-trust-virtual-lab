<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_view_edit_or_delete_other_students_vm(): void
    {
        $this->seed();

        $otherStudentVm = $this->createVmForOwner(4, 'Siswa 2 Private VM');

        $this->getJson("/api/vms/{$otherStudentVm->id}?owner_id=3")
            ->assertForbidden();

        $this->putJson("/api/vms/{$otherStudentVm->id}", [
            'owner_id' => 3,
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
        ])->assertForbidden();

        $this->deleteJson("/api/vms/{$otherStudentVm->id}", [
            'owner_id' => 3,
        ])->assertForbidden();

        $this->assertDatabaseHas('vms', [
            'id' => $otherStudentVm->id,
            'user_id' => 4,
            'deleted_at' => null,
        ]);
    }

    public function test_student_cannot_access_audit_logs(): void
    {
        $this->seed();
        AuditLog::create([
            'user_id' => 3,
            'action' => 'permission.test',
            'description' => 'Permission boundary test.',
        ]);

        $this->getJson('/api/audit-logs?owner_id=3')
            ->assertForbidden();
    }

    public function test_admin_can_view_all_vms(): void
    {
        $this->seed();

        $this->createVmForOwner(3, 'Siswa 1 VM');
        $this->createVmForOwner(4, 'Siswa 2 VM');

        $this->getJson('/api/vms?owner_id=2')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_admin_can_access_audit_logs(): void
    {
        $this->seed();
        AuditLog::create([
            'user_id' => 3,
            'action' => 'permission.test',
            'description' => 'Permission boundary test.',
        ]);

        $this->getJson('/api/audit-logs?owner_id=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    private function createVmForOwner(int $ownerId, string $name): Vm
    {
        $user = User::findOrFail($ownerId);

        return $user->vms()->create([
            'name' => $name,
            'proxmox_id' => 'permission-vm-'.$ownerId.'-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ]);
    }
}
