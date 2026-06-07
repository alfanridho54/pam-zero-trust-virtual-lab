<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabTemplate;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockProxmoxApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_proxmox_api_flow(): void
    {
        $this->seed();

        $this->assertSame('admin@lab.test', User::find(1)->email);
        $this->assertSame('admin2@lab.test', User::find(2)->email);
        $this->assertSame('admin', User::find(2)->role);
        $this->assertSame('siswa1@lab.test', User::find(3)->email);
        $this->assertSame('student', User::find(3)->role);

        $templatesResponse = $this->getJson('/api/lab-templates')
            ->assertOk()
            ->assertJsonCount(4, 'data.data');

        $this->assertEqualsCanonicalizing(
            ['Docker Lab', 'Linux Basic', 'Podman Lab', 'Web Server Lab'],
            collect($templatesResponse->json('data.data'))->pluck('name')->all(),
        );

        $createResponse = $this->postJson('/api/vms', [
            'owner_id' => 3,
            'name' => 'VM Siswa 1',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', 3)
            ->assertJsonPath('data.name', 'VM Siswa 1');

        $vmId = $createResponse->json('data.id');

        $this->getJson('/api/vms')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/vms?owner_id=3')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user_id', 3);

        $this->putJson("/api/vms/{$vmId}", [
            'owner_id' => 3,
            'cpu_cores' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
        ])
            ->assertOk()
            ->assertJsonPath('data.cpu_cores', 2)
            ->assertJsonPath('data.memory_mb', 2048)
            ->assertJsonPath('data.disk_gb', 20);

        $this->deleteJson("/api/vms/{$vmId}", ['owner_id' => 3])
            ->assertOk()
            ->assertJsonPath('message', 'VM berhasil dihapus.');

        $this->assertSoftDeleted('vms', ['id' => $vmId]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => 3,
            'vm_id' => $vmId,
            'action' => 'vm.deleted',
        ]);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.total', 3);
    }

    public function test_lab_access_creates_vm_from_template(): void
    {
        $this->seed();

        $template = LabTemplate::where('name', 'Linux Basic')->firstOrFail();

        $this->postJson("/api/lab-access/{$template->id}", [
            'owner_id' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', 3)
            ->assertJsonPath('data.lab_template_id', $template->id);

        $this->getJson('/api/lab-access?owner_id=3')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame(1, Vm::where('user_id', 3)->where('lab_template_id', $template->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'lab.accessed')->count());
    }
}
