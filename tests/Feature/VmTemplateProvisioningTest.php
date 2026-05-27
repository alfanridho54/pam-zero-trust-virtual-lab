<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Models\VmTemplate;
use App\Services\ProxmoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VmTemplateProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_only_see_enabled_templates(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $enabled = $this->template(['name' => 'Enabled Ubuntu Template', 'enabled' => true]);
        $disabled = $this->template(['name' => 'Disabled Kali Template', 'enabled' => false]);

        $this->actingAs($student)
            ->get(route('student.vms.index'))
            ->assertOk()
            ->assertSee($enabled->name)
            ->assertDontSee($disabled->name);
    }

    public function test_admin_can_manage_templates_without_exposing_secret(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('dashboard.vm-templates.store'), [
                'name' => 'Managed Template',
                'description' => 'Created by admin.',
                'proxmox_template_id' => 9010,
                'proxmox_node' => 'pve2',
                'cpu' => 2,
                'ram' => 2048,
                'disk' => 20,
                'ssh_username' => 'student',
                'ssh_password' => 'template-secret-password',
                'enabled' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $template = VmTemplate::where('name', 'Managed Template')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('dashboard.vm-templates.update', $template), [
                'name' => 'Managed Template Updated',
                'description' => 'Updated by admin.',
                'proxmox_template_id' => 9011,
                'proxmox_node' => 'pve3',
                'cpu' => 4,
                'ram' => 4096,
                'disk' => 40,
                'ssh_username' => 'labuser',
                'enabled' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $template->refresh();

        $this->assertSame('Managed Template Updated', $template->name);
        $this->assertFalse($template->enabled);
        $this->assertSame('template-secret-password', $template->ssh_password);

        $auditMetadata = AuditLog::whereIn('action', ['vm_template.created', 'vm_template.updated'])
            ->get()
            ->map(fn (AuditLog $log) => json_encode($log->metadata))
            ->implode("\n");

        $this->assertStringNotContainsString('template-secret-password', $auditMetadata);

        $this->actingAs($admin)
            ->delete(route('dashboard.vm-templates.destroy', $template))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('vm_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_provisioning_clones_correct_proxmox_template(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'ignored-node',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_wait_for_clone' => false,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template([
            'name' => 'Real Proxmox Template',
            'proxmox_template_id' => 777,
            'proxmox_node' => 'pve-real',
            'cpu' => 2,
            'ram' => 3072,
            'disk' => 30,
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve-real/qemu/777/config' => Http::response([
                'data' => ['template' => 1, 'name' => 'real-template'],
            ]),
            'https://proxmox.test:8006/api2/json/cluster/nextid' => Http::response([
                'data' => '188',
            ]),
            'https://proxmox.test:8006/api2/json/nodes/pve-real/qemu/188/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://proxmox.test:8006/api2/json/nodes/pve-real/qemu/777/clone' => Http::response([
                'data' => 'UPID:pve-real:000001:000002:clone:188:root@pam:',
            ]),
        ]);

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Provisioned From DB Template',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $vm = Vm::where('name', 'Provisioned From DB Template')->firstOrFail();

        $this->assertSame($template->id, $vm->vm_template_id);
        $this->assertSame('pve-real', $vm->node);
        $this->assertSame(777, $vm->metadata['source_template_vmid']);
        $this->assertSame(2, $vm->cpu_cores);
        $this->assertSame(3072, $vm->memory_mb);
        $this->assertSame(30, $vm->disk_gb);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://proxmox.test:8006/api2/json/nodes/pve-real/qemu/777/clone'
            && (int) $request['newid'] === 188
            && $request['name'] === 'Provisioned From DB Template');
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://pve-real:8006/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/nodes/ignored-node/'));
    }

    public function test_template_node_is_not_used_as_api_hostname(): void
    {
        config([
            'services.proxmox.host' => '172.16.1.1:8006',
            'services.proxmox.node' => 'ignored-node',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
            'services.proxmox.student_wait_for_clone' => false,
        ]);

        $template = $this->template([
            'proxmox_template_id' => 778,
            'proxmox_node' => 'pve',
        ]);

        Http::fake([
            'https://172.16.1.1:8006/api2/json/nodes/pve/qemu/778/config' => Http::response([
                'data' => ['template' => 1, 'name' => 'real-template'],
            ]),
            'https://172.16.1.1:8006/api2/json/cluster/nextid' => Http::response([
                'data' => '189',
            ]),
            'https://172.16.1.1:8006/api2/json/nodes/pve/qemu/189/status/current' => Http::response([
                'message' => 'not found',
            ], 500),
            'https://172.16.1.1:8006/api2/json/nodes/pve/qemu/778/clone' => Http::response([
                'data' => 'UPID:pve:000001:000002:clone:189:root@pam:',
            ]),
        ]);

        $result = app(ProxmoxService::class)->cloneStudentVmFromVmTemplate($template, 'node-host-regression');

        $this->assertTrue($result['success']);
        $this->assertSame('pve', $result['node']);

        Http::assertSent(fn ($request) => $request->url() === 'https://172.16.1.1:8006/api2/json/nodes/pve/qemu/778/clone');
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://pve:8006/'));
    }

    public function test_template_ssh_metadata_is_attached_safely(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template([
            'ssh_username' => 'labuser',
            'ssh_password' => 'template-secret',
        ]);

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'SSH Metadata VM',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm = Vm::where('name', 'SSH Metadata VM')->firstOrFail();

        $this->assertSame('labuser', $vm->metadata['ssh_username']);
        $this->assertSame('template-secret', $vm->metadata['ssh_password']);

        $auditMetadata = json_encode(AuditLog::where('action', 'student.vm.created')->firstOrFail()->metadata);

        $this->assertStringNotContainsString('template-secret', $auditMetadata);
    }

    public function test_detect_guest_ipv4_prefers_student_lab_subnet_from_guest_agent(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/2601/agent/network-get-interfaces' => Http::response([
                'data' => [
                    'result' => [
                        [
                            'name' => 'eth0',
                            'ip-addresses' => [
                                ['ip-address-type' => 'ipv4', 'ip-address' => '10.10.10.21'],
                            ],
                        ],
                        [
                            'name' => 'eth1',
                            'ip-addresses' => [
                                ['ip-address-type' => 'ipv4', 'ip-address' => '172.16.1.12'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertSame('172.16.1.12', app(ProxmoxService::class)->detectGuestIpv4('pve1', 2601));
    }

    public function test_detect_guest_ipv4_ignores_loopback_link_local_and_ipv6(): void
    {
        config([
            'services.proxmox.host' => 'https://proxmox.test:8006',
            'services.proxmox.node' => 'pve1',
            'services.proxmox.token_id' => 'root@pam!test',
            'services.proxmox.token_secret' => 'secret',
        ]);

        Http::fake([
            'https://proxmox.test:8006/api2/json/nodes/pve1/qemu/2602/agent/network-get-interfaces' => Http::response([
                'data' => [
                    'result' => [
                        [
                            'name' => 'lo',
                            'ip-addresses' => [
                                ['ip-address-type' => 'ipv4', 'ip-address' => '127.0.0.1'],
                                ['ip-address-type' => 'ipv6', 'ip-address' => 'fe80::1'],
                            ],
                        ],
                        [
                            'name' => 'eth0',
                            'ip-addresses' => [
                                ['ip-address-type' => 'ipv4', 'ip-address' => '169.254.10.20'],
                                ['ip-address-type' => 'ipv4', 'ip-address' => '192.168.56.11'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertSame('192.168.56.11', app(ProxmoxService::class)->detectGuestIpv4('pve1', 2602));
    }

    public function test_provisioning_stores_detected_guest_ipv4_as_ssh_metadata(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template([
            'ssh_username' => 'labuser',
            'ssh_password' => 'template-secret',
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'status' => 202,
                    'message' => 'OK',
                    'data' => ['mock' => true],
                    'proxmox_id' => '2603',
                    'node' => $template->proxmox_node,
                    'vmid' => 2603,
                    'name' => $vmName,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'local_status' => 'running',
                ];
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return $node === 'pve-mock' && $vmid === 2603 ? '172.16.1.15' : null;
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Detected IP VM',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $vm = Vm::where('name', 'Detected IP VM')->firstOrFail();

        $this->assertSame('172.16.1.15', $vm->metadata['ssh_host']);
        $this->assertSame('172.16.1.15', $vm->metadata['ip_address']);
        $this->assertSame(22, $vm->metadata['ssh_port']);
        $this->assertSame('labuser', $vm->metadata['ssh_username']);
        $this->assertSame('template-secret', $vm->metadata['ssh_password']);

        $auditMetadata = json_encode(AuditLog::where('action', 'student.vm.created')->firstOrFail()->metadata);

        $this->assertStringNotContainsString('template-secret', $auditMetadata);
    }

    public function test_provisioning_succeeds_when_guest_agent_ip_is_unavailable(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template();

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'status' => 202,
                    'message' => 'OK',
                    'data' => ['mock' => true],
                    'proxmox_id' => '2604',
                    'node' => $template->proxmox_node,
                    'vmid' => 2604,
                    'name' => $vmName,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'local_status' => 'running',
                ];
            }

            public function detectGuestIpv4(string $node, int $vmid): ?string
            {
                return null;
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Guest Agent Pending VM',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect(route('student.vms.index'))
            ->assertSessionHas('status');

        $vm = Vm::where('name', 'Guest Agent Pending VM')->firstOrFail();

        $this->assertArrayNotHasKey('ssh_host', $vm->metadata ?? []);
        $this->assertArrayNotHasKey('ip_address', $vm->metadata ?? []);
    }

    public function test_disabled_template_cannot_be_provisioned(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template(['enabled' => false]);

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Disabled Template VM',
                'vm_template_id' => $template->id,
            ])
            ->assertSessionHasErrors('vm_template_id');

        $this->assertDatabaseMissing('vms', [
            'name' => 'Disabled Template VM',
        ]);
    }

    public function test_terminal_access_still_works_for_template_provisioned_vm(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $template = $this->template([
            'ssh_username' => 'labuser',
            'ssh_password' => 'template-secret',
        ]);

        $this->app->instance(ProxmoxService::class, new class extends ProxmoxService
        {
            public function __construct()
            {
            }

            public function cloneStudentVmFromVmTemplate(VmTemplate $template, string $vmName): array
            {
                return [
                    'success' => true,
                    'status' => 202,
                    'message' => 'OK',
                    'data' => ['mock' => true],
                    'proxmox_id' => '2601',
                    'node' => $template->proxmox_node,
                    'vmid' => 2601,
                    'name' => $vmName,
                    'source_template_vmid' => $template->proxmox_template_id,
                    'ssh_host' => '10.50.0.21',
                    'local_status' => 'running',
                ];
            }
        });

        $this->actingAs($student)
            ->post(route('student.vms.store'), [
                'name' => 'Terminal Ready Template VM',
                'vm_template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm = Vm::where('name', 'Terminal Ready Template VM')->firstOrFail();

        $this->actingAs($student)
            ->post(route('terminal-sessions.store', $vm))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('terminal_sessions', [
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'ssh_host' => '10.50.0.21',
            'ssh_username' => 'labuser',
        ]);

        $this->assertSame(1, TerminalSession::where('vm_id', $vm->id)->count());
    }

    private function template(array $attributes = []): VmTemplate
    {
        return VmTemplate::create([
            'name' => 'Ubuntu Practice Template '.str()->random(6),
            'description' => 'Template for student lab provisioning.',
            'proxmox_template_id' => random_int(9001, 9999),
            'proxmox_node' => 'pve-mock',
            'cpu' => 1,
            'ram' => 1024,
            'disk' => 10,
            'ssh_username' => 'student',
            'enabled' => true,
            ...$attributes,
        ]);
    }
}
