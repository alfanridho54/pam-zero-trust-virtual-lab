<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxmoxStudentCloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_template_clone_uses_configured_node_template_nextid_and_payload(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_template_vmid' => 102,
            'services.proxmox.student_clone_full' => true,
            'services.proxmox.student_storage' => null,
            'services.proxmox.student_wait_for_clone' => true,
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/config' => Http::response([
                'data' => ['template' => 1, 'name' => 'student-template'],
            ]),
            'https://proxmox.test:8006/api2/json/cluster/nextid' => Http::response([
                'data' => '150',
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/150/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/clone' => Http::response([
                'data' => 'UPID:pve1:000001:000002:clone:150:root@pam:',
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve1/tasks/*/status' => Http::response([
                'data' => ['status' => 'stopped', 'exitstatus' => 'OK'],
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/150/config' => Http::response([
                'data' => null,
            ]),
        ]);

        $result = app(ProxmoxService::class)->cloneStudentVmFromTemplate('student-vm-150');

        $this->assertTrue($result['success']);
        $this->assertSame('150', $result['proxmox_id']);
        $this->assertSame(150, $result['vmid']);
        $this->assertSame('pve1', $result['node']);
        $this->assertSame(102, $result['source_template_vmid']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/clone'
            && (int) $request['newid'] === 150
            && $request['name'] === 'student-vm-150'
            && (int) $request['full'] === 1);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/150/config'
            && $request['name'] === 'student-vm-150');
    }

    public function test_student_template_clone_returns_clear_permission_hint_on_forbidden_clone(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_template_vmid' => 102,
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/config' => Http::response([
                'data' => ['template' => 1, 'name' => 'student-template'],
            ]),
            'https://proxmox.test:8006/api2/json/cluster/nextid' => Http::response([
                'data' => '151',
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/151/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/clone' => Http::response([
                'message' => 'Permission check failed',
            ], 403),
        ]);

        $result = app(ProxmoxService::class)->cloneStudentVmFromTemplate('student-vm-denied');

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('VM.Clone', $result['message']);
        $this->assertStringContainsString('Datastore.AllocateSpace', $result['message']);
    }

    public function test_student_template_clone_rejects_non_template_vmid(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_template_vmid' => 102,
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/config' => Http::response([
                'data' => ['template' => 0, 'name' => 'ordinary-vm'],
            ]),
        ]);

        $result = app(ProxmoxService::class)->cloneStudentVmFromTemplate('student-vm');

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('bukan Proxmox template', $result['message']);
    }

    public function test_student_template_clone_skips_soft_deleted_local_proxmox_id(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_template_vmid' => 102,
            'services.proxmox.student_wait_for_clone' => false,
        ]);

        $user = User::factory()->create();
        $trashedVm = $user->vms()->create([
            'name' => 'Old Soft Deleted VM',
            'proxmox_id' => '104',
            'node' => 'pve1',
            'status' => 'deleted',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => ['vmid' => 104],
        ]);
        $trashedVm->delete();

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/config' => Http::response([
                'data' => ['template' => 1, 'name' => 'student-template'],
            ]),
            'https://proxmox.test:8006/api2/json/cluster/nextid' => Http::response([
                'data' => '104',
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/104/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/105/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/clone' => Http::response([
                'data' => 'UPID:pve1:000001:000002:clone:105:root@pam:',
            ]),
        ]);

        $result = app(ProxmoxService::class)->cloneStudentVmFromTemplate('student-vm-105');

        $this->assertTrue($result['success']);
        $this->assertSame('105', $result['proxmox_id']);
        $this->assertSame(105, $result['vmid']);
        $this->assertSame(104, $result['vmid_allocation'][0]['vmid']);
        $this->assertTrue($result['vmid_allocation'][0]['local_reserved']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/102/clone'
            && (int) $request['newid'] === 105);
    }
}
