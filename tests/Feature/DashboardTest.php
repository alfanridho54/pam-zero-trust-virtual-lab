<?php

namespace Tests\Feature;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_pages_render(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard PAM Proxmox')
            ->assertSee('Template Library')
            ->assertSee('VM Access Control');

        $this->actingAs($admin)
            ->get('/dashboard/templates')
            ->assertOk()
            ->assertSee('Linux Basic')
            ->assertSee('Docker Lab');

        $this->actingAs($admin)
            ->get('/dashboard/vms')
            ->assertOk()
            ->assertSee('Virtual Machine');

        $this->actingAs($admin)
            ->get('/dashboard/audit-logs')
            ->assertOk()
            ->assertSee('Audit Log');
    }

    public function test_logout_uses_post_and_requires_cloudflare_or_session_again(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Logout');

        $this->post(route('logout'))
            ->assertRedirect(route('signed-out'));

        $this->assertGuest();

        $this->get(route('dashboard'))
            ->assertRedirect('/');
    }

    public function test_signed_out_page_does_not_recreate_user_from_cloudflare_header(): void
    {
        $this->withHeader('Cf-Access-Authenticated-User-Email', 'signed-out-student@example.test')
            ->get(route('signed-out'))
            ->assertOk()
            ->assertSee('You have been signed out from PAM Virtual Lab.')
            ->assertSee('If Cloudflare Access session is still active, reopening the app may sign you in again.');

        $this->assertDatabaseMissing('users', [
            'email' => 'signed-out-student@example.test',
        ]);
    }

    public function test_root_redirects_authenticated_users_to_role_dashboard(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();
        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/')
            ->assertRedirect(route('dashboard'));

        Auth::logout();

        $this->actingAs($student)
            ->get('/')
            ->assertRedirect(route('student.dashboard'));
    }

    public function test_root_uses_cloudflare_access_identity_as_dashboard_entry(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();

        $this->withHeader('Cf-Access-Authenticated-User-Email', $admin->email)
            ->get('/')
            ->assertRedirect(route('dashboard'));

        $this->withHeader('Cf-Access-Authenticated-User-Email', 'cloudflare-student@example.test')
            ->get('/')
            ->assertRedirect(route('student.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'cloudflare-student@example.test',
            'role' => 'student',
        ]);
    }

    public function test_student_activity_history_is_separate_from_admin_audit_logs(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $otherStudent = User::where('email', 'siswa2@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Student Activity VM');
        $otherVm = $this->createVmForOwner($otherStudent, 'Other Student Activity VM');
        $terminalSession = $this->createTerminalSession($student, $vm);

        AuditLog::create([
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'action' => 'student.vm.start.success',
            'description' => 'Student menjalankan lifecycle action VM.',
        ]);

        AuditLog::create([
            'user_id' => $otherStudent->id,
            'vm_id' => $otherVm->id,
            'action' => 'student.vm.shutdown.success',
            'description' => 'Other student hidden activity.',
        ]);

        CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'command' => 'whoami',
            'status' => CommandLogStatus::Succeeded,
            'executed_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('student.activity-history'))
            ->assertOk()
            ->assertSee('Activity History')
            ->assertSee('Your recent lab activity inside the PAM protected workspace.')
            ->assertSee('VM started')
            ->assertSee('Command executed')
            ->assertSee($vm->name)
            ->assertDontSee('Recent Audit Events')
            ->assertDontSee('Other student hidden activity')
            ->assertDontSee($otherVm->name);

        $this->actingAs($student)
            ->get(route('dashboard.audit-logs'))
            ->assertForbidden();
    }

    public function test_student_lab_guide_uses_student_layout_and_dashboard_templates_redirect(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();

        $this->actingAs($student)
            ->get(route('student.lab-guide'))
            ->assertOk()
            ->assertSee('Student Lab Guide')
            ->assertSee('Available Lab Templates')
            ->assertSee('Linux Basic')
            ->assertSee('Docker Lab')
            ->assertSee('Student workspace')
            ->assertDontSee('Zero Trust Dashboard');

        $this->actingAs($student)
            ->get(route('dashboard.templates'))
            ->assertRedirect(route('student.lab-guide'));
    }

    public function test_dashboard_simulation_buttons_work(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();

        $this->actingAs($admin)
            ->post('/dashboard/simulate/docker-lab')
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm = Vm::firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/simulate/vms/{$vm->id}/resources")
            ->assertRedirect()
            ->assertSessionHas('status');

        $vm->refresh();
        $this->assertSame(3, $vm->cpu_cores);
        $this->assertSame(2560, $vm->memory_mb);
        $this->assertSame(25, $vm->disk_gb);

        $this->actingAs($admin)
            ->delete("/dashboard/simulate/vms/{$vm->id}")
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSoftDeleted('vms', ['id' => $vm->id]);
    }

    public function test_admin_can_access_soc_monitoring_dashboard(): void
    {
        $this->seed();
        config(['services.terminal.ssh_password' => 'secret-ssh-password']);

        $admin = User::where('email', 'admin@lab.test')->firstOrFail();
        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'ubuntu-student-01');
        $terminalSession = $this->createTerminalSession($student, $vm);

        CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'command' => 'whoami && hostname && uptime',
            'status' => CommandLogStatus::Succeeded,
            'output_excerpt' => "student\nsecret-ssh-password\nubuntu-student-01\n",
            'executed_at' => now()->subMinute(),
        ]);

        CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'command' => 'reboot',
            'status' => CommandLogStatus::Blocked,
            'blocked_reason' => 'Command diblokir oleh policy terminal.',
            'executed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.soc'))
            ->assertOk()
            ->assertSee('SOC PAM Monitoring')
            ->assertSee('Recent Command Logs')
            ->assertSee('Active Terminal Sessions')
            ->assertSee('Blocked Command Monitoring')
            ->assertSee('PAM Activity Timeline')
            ->assertSee('Revoke')
            ->assertSee('Siswa 1')
            ->assertSee('siswa1@lab.test')
            ->assertSee('ubuntu-student-01')
            ->assertSee('whoami &amp;&amp; hostname &amp;&amp; uptime', false)
            ->assertSee('reboot')
            ->assertSee('succeeded')
            ->assertSee('blocked')
            ->assertSee('Command diblokir oleh policy terminal.')
            ->assertSee('Command blocked')
            ->assertSee('Session started')
            ->assertDontSee('secret-ssh-password');
    }

    public function test_guru_cannot_access_soc_monitoring_dashboard(): void
    {
        $this->seed();

        $guru = User::where('email', 'teacher@lab.test')->firstOrFail();

        $this->actingAs($guru)
            ->get(route('dashboard.soc'))
            ->assertForbidden();
    }

    public function test_student_cannot_see_recent_command_logs_section(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $vm = $this->createVmForOwner($student, 'Student Command VM');
        $terminalSession = $this->createTerminalSession($student, $vm);

        CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $student->id,
            'vm_id' => $vm->id,
            'command' => 'id',
            'status' => CommandLogStatus::Succeeded,
            'output_excerpt' => 'uid=1000(student)',
            'executed_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('dashboard.soc'))
            ->assertForbidden();

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('SOC PAM Monitoring')
            ->assertDontSee('Recent Command Logs')
            ->assertDontSee('Active Terminal Sessions')
            ->assertDontSee('Blocked Command Monitoring')
            ->assertDontSee('Terminal Activity Timeline')
            ->assertDontSee('uid=1000(student)');
    }

    public function test_student_main_dashboard_only_shows_normal_assigned_vm(): void
    {
        $this->seed();

        $student = User::where('email', 'siswa1@lab.test')->firstOrFail();
        $systemVm = $this->createVmForOwner($student, 'Windows10 Student Infrastructure', [
            'proxmox_id' => '100',
            'metadata' => ['system_vm' => true, 'critical' => true, 'vmid' => 100],
        ]);
        $criticalVm = $this->createVmForOwner($student, 'Critical Student Template', [
            'proxmox_id' => '102',
            'metadata' => ['critical' => true, 'vmid' => 102],
        ]);
        $deletedVm = $this->createVmForOwner($student, 'Deleted Student Lab VM');
        $deletedVm->delete();
        $normalVm = $this->createVmForOwner($student, 'Normal Student Assigned VM');

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($normalVm->name)
            ->assertDontSee($systemVm->name)
            ->assertDontSee($criticalVm->name)
            ->assertDontSee($deletedVm->name)
            ->assertSee('1 assigned');
    }

    private function createVmForOwner(User $owner, string $name, array $overrides = []): Vm
    {
        return $owner->vms()->create([
            'name' => $name,
            'proxmox_id' => $overrides['proxmox_id'] ?? 'dashboard-command-vm-'.str()->random(8),
            'node' => 'pve-mock',
            'status' => $overrides['status'] ?? 'running',
            'cpu_cores' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'metadata' => $overrides['metadata'] ?? [
                'ssh_host' => '127.0.0.1',
                'ssh_port' => 22,
                'ssh_username' => 'student',
            ],
        ]);
    }

    private function createTerminalSession(User $user, Vm $vm): TerminalSession
    {
        return TerminalSession::create([
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => 101,
            'ssh_host' => '127.0.0.1',
            'ssh_port' => 22,
            'ssh_username' => 'student',
            'status' => TerminalSessionStatus::Active,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [],
        ]);
    }
}
