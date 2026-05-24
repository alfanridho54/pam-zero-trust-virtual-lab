<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\Vm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VmOwnershipQuotaAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_own_vm(): void
    {
        $this->seed();

        $response = $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Student Owned VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user_id', 3)
            ->assertJsonPath('data.name', 'Student Owned VM');

        $this->assertDatabaseHas('vms', [
            'user_id' => 3,
            'name' => 'Student Owned VM',
        ]);
    }

    public function test_student_cannot_update_other_students_vm(): void
    {
        $this->seed();

        $otherStudentVm = $this->createVmForOwner(4, 'Siswa 2 VM');

        $this->putJson("/api/vms/{$otherStudentVm->id}", [
            'owner_id' => 3,
            'cpu_cores' => 4,
            'memory_mb' => 4096,
            'disk_gb' => 30,
        ])->assertForbidden();

        $this->assertDatabaseHas('vms', [
            'id' => $otherStudentVm->id,
            'user_id' => 4,
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ]);
    }

    public function test_student_cannot_delete_other_students_vm(): void
    {
        $this->seed();

        $otherStudentVm = $this->createVmForOwner(4, 'Siswa 2 VM');

        $this->deleteJson("/api/vms/{$otherStudentVm->id}", [
            'owner_id' => 3,
        ])->assertForbidden();

        $this->assertDatabaseHas('vms', [
            'id' => $otherStudentVm->id,
            'deleted_at' => null,
        ]);
    }

    public function test_quota_max_three_vms_rejects_fourth_vm(): void
    {
        $this->seed();

        UserQuota::updateOrCreate(
            ['user_id' => 3],
            [
                'max_vms' => 3,
                'max_cpu_cores' => 4,
                'max_memory_mb' => 4096,
                'max_disk_gb' => 40,
            ],
        );

        foreach (range(1, 3) as $number) {
            $this->postJson('/api/vms', [
                'owner_id' => 3,
                'name' => "Quota VM {$number}",
                'cpu_cores' => 1,
                'memory_mb' => 1024,
                'disk_gb' => 10,
            ])->assertCreated();
        }

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Quota VM 4',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota VM sudah penuh.');

        $this->assertSame(3, Vm::where('user_id', 3)->count());
    }

    public function test_soft_deleted_vms_do_not_count_toward_vm_quota(): void
    {
        $this->seed();

        UserQuota::updateOrCreate(
            ['user_id' => 3],
            [
                'max_vms' => 3,
                'max_cpu_cores' => 4,
                'max_memory_mb' => 4096,
                'max_disk_gb' => 40,
            ],
        );

        $firstVmId = null;

        foreach (range(1, 3) as $number) {
            $response = $this->postJson('/api/vms', [
                'owner_id' => 3,
                'name' => "Soft Quota VM {$number}",
                'cpu_cores' => 1,
                'memory_mb' => 1024,
                'disk_gb' => 10,
            ])->assertCreated();

            $firstVmId ??= $response->json('data.id');
        }

        $this->deleteJson("/api/vms/{$firstVmId}", [
            'owner_id' => 3,
        ])->assertOk();

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Replacement VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ])->assertCreated();

        $this->assertSame(3, Vm::where('user_id', 3)->count());
        $this->assertSame(4, Vm::withTrashed()->where('user_id', 3)->count());
    }

    public function test_storage_quota_enforces_total_active_disk_usage(): void
    {
        $this->seed();

        UserQuota::updateOrCreate(
            ['user_id' => 3],
            [
                'max_vms' => 3,
                'max_cpu_cores' => 4,
                'max_memory_mb' => 4096,
                'max_disk_gb' => 40,
            ],
        );

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Storage VM 1',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ])->assertCreated();

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Storage VM 2',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ])->assertCreated();

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Storage VM 3',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota storage VM sudah penuh.');

        $this->assertSame(40, (int) Vm::where('user_id', 3)->sum('disk_gb'));
    }

    public function test_storage_quota_becomes_available_after_vm_is_deleted(): void
    {
        $this->seed();

        UserQuota::updateOrCreate(
            ['user_id' => 3],
            [
                'max_vms' => 3,
                'max_cpu_cores' => 4,
                'max_memory_mb' => 4096,
                'max_disk_gb' => 40,
            ],
        );

        $firstResponse = $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Deleted Storage VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ])->assertCreated();

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Remaining Storage VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ])->assertCreated();

        $this->deleteJson('/api/vms/'.$firstResponse->json('data.id'), [
            'owner_id' => 3,
        ])->assertOk();

        $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Replacement Storage VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 20,
        ])->assertCreated();

        $this->assertSame(40, (int) Vm::where('user_id', 3)->sum('disk_gb'));
        $this->assertSame(60, (int) Vm::withTrashed()->where('user_id', 3)->sum('disk_gb'));
    }

    public function test_create_update_delete_write_audit_logs(): void
    {
        $this->seed();

        $createResponse = $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'Audited VM',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ])->assertCreated();

        $vmId = $createResponse->json('data.id');

        $this->putJson("/api/vms/{$vmId}", [
            'owner_id' => 3,
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
        ])->assertOk();

        $this->deleteJson("/api/vms/{$vmId}", [
            'owner_id' => 3,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => 3,
            'vm_id' => $vmId,
            'action' => 'vm.created',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => 3,
            'vm_id' => $vmId,
            'action' => 'vm.updated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => 3,
            'vm_id' => $vmId,
            'action' => 'vm.deleted',
        ]);

        $this->assertSame(3, AuditLog::where('vm_id', $vmId)->count());
    }

    public function test_delete_uses_soft_delete_not_hard_delete(): void
    {
        $this->seed();

        $vm = $this->createVmForOwner(3, 'Soft Deleted VM');

        $this->deleteJson("/api/vms/{$vm->id}", [
            'owner_id' => 3,
        ])->assertOk();

        $this->assertSoftDeleted('vms', ['id' => $vm->id]);
        $this->assertNotNull(Vm::withTrashed()->find($vm->id));
        $this->assertNull(Vm::find($vm->id));
    }

    private function createVmForOwner(int $ownerId, string $name): Vm
    {
        $user = User::findOrFail($ownerId);

        return $user->vms()->create([
            'name' => $name,
            'proxmox_id' => 'test-vm-'.$ownerId.'-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ]);
    }
}
