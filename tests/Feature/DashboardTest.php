<?php

namespace Tests\Feature;

use App\Models\Vm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_pages_render(): void
    {
        $this->seed();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard PAM Proxmox')
            ->assertSee('Akses VM Praktikum')
            ->assertSee('Kelola Lab Pribadi');

        $this->get('/dashboard/templates')
            ->assertOk()
            ->assertSee('Linux Basic')
            ->assertSee('Docker Lab');

        $this->get('/dashboard/vms')
            ->assertOk()
            ->assertSee('Virtual Machine');

        $this->get('/dashboard/audit-logs')
            ->assertOk()
            ->assertSee('Audit Log');
    }

    public function test_dashboard_simulation_buttons_work(): void
    {
        $this->seed();

        $this->post('/dashboard/simulate/docker-lab')
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm = Vm::firstOrFail();

        $this->post("/dashboard/simulate/vms/{$vm->id}/resources")
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();
        $this->assertSame(3, $vm->cpu_cores);
        $this->assertSame(2560, $vm->memory_mb);
        $this->assertSame(25, $vm->disk_gb);

        $this->delete("/dashboard/simulate/vms/{$vm->id}")
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSoftDeleted('vms', ['id' => $vm->id]);
    }
}
