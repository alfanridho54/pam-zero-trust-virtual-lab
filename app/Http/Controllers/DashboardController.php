<?php

namespace App\Http\Controllers;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\CommandLog;
use App\Models\LabTemplate;
use App\Models\PracticalVmAccess;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Models\VmTemplate;
use App\Services\ProxmoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ProxmoxService $proxmox) {}

    public function index(): View
    {
        return $this->view('overview');
    }

    public function templates(): RedirectResponse|View
    {
        $user = $this->resolveDashboardUser(request());

        if ($this->roleFor($user) === 'student') {
            return redirect()->route('student.lab-guide');
        }

        return $this->view('templates');
    }

    public function studentLabGuide(Request $request): View
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'student', 403, 'Anda tidak memiliki akses ke lab guide siswa.');

        return view('dashboard.student-lab-guide', [
            'currentUser' => $user,
            'vmTemplates' => VmTemplate::query()->enabled()->orderBy('name')->get(),
        ]);
    }

    public function vms(): RedirectResponse|View
    {
        $user = $this->resolveDashboardUser(request());

        if ($this->roleFor($user) === 'student') {
            return redirect()->route('student.vms.index');
        }

        return $this->view('vms');
    }

    public function auditLogs(): View
    {
        $user = $this->resolveDashboardUser(request());
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses ke audit logs.');

        return $this->view('audit-logs');
    }

    public function studentActivityHistory(Request $request): View
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'student', 403, 'Anda tidak memiliki akses ke activity history siswa.');

        $auditLogs = $this->studentAuditLogs($user, 50);
        $commandLogs = $this->studentCommandLogs($user, 50);
        $terminalSessions = TerminalSession::with('vm')
            ->forUser($user)
            ->latest('last_activity_at')
            ->limit(50)
            ->get();

        $lastActivity = collect([
            $auditLogs->max('created_at'),
            $commandLogs->max('executed_at'),
            $terminalSessions->max('last_activity_at'),
        ])->filter()->max();

        return view('dashboard.student-activity-history', [
            'currentUser' => $user,
            'summary' => [
                'terminalSessions' => TerminalSession::forUser($user)->count(),
                'commandsExecuted' => CommandLog::forUser($user)->count(),
                'blockedCommands' => CommandLog::forUser($user)->blocked()->count(),
                'lastActivity' => $lastActivity,
            ],
            'activityItems' => $this->studentActivityTimeline($auditLogs, $commandLogs),
        ]);
    }

    public function socMonitoring(Request $request): View
    {
        $user = $this->resolveDashboardUser($request);
        // Dashboard SOC hanya untuk admin karena berisi aktivitas terminal lintas user.
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses ke SOC monitoring.');

        return $this->view('soc');
    }

    public function createDockerLab(): RedirectResponse
    {
        $owner = User::findOrFail(3);
        $template = LabTemplate::where('name', 'Docker Lab')->firstOrFail();
        $vmName = 'Docker Lab - '.$owner->name;
        $proxmox = $this->proxmox->cloneFromTemplate($template, $vmName);

        $vm = $owner->vms()->create([
            'lab_template_id' => $template->id,
            'name' => $proxmox['name'],
            'proxmox_id' => $proxmox['proxmox_id'],
            'node' => $proxmox['node'],
            'status' => $proxmox['status'],
            'cpu_cores' => $template->cpu_cores,
            'memory_mb' => $template->memory_mb,
            'disk_gb' => $template->disk_gb,
            'metadata' => ['source_template_id' => $proxmox['source_template_id']],
        ]);

        $this->audit($owner->id, $vm->id, 'dashboard.vm.created', 'Dashboard membuat Docker Lab untuk siswa1.');

        return back()->with('status', 'Docker Lab berhasil dibuat untuk siswa1.');
    }

    public function editVmResource(Request $request, Vm $vm): RedirectResponse
    {
        $vm->update([
            'cpu_cores' => max(1, $vm->cpu_cores + 1),
            'memory_mb' => $vm->memory_mb + 512,
            'disk_gb' => $vm->disk_gb + 5,
            'status' => 'running',
        ]);

        $this->audit($vm->user_id, $vm->id, 'dashboard.vm.updated', 'Dashboard mengubah resource VM.');

        return back()->with('status', 'Resource VM berhasil disimulasikan.');
    }

    public function deleteVm(Vm $vm): RedirectResponse
    {
        $this->proxmox->deleteVm($vm);
        $this->audit($vm->user_id, $vm->id, 'dashboard.vm.deleted', 'Dashboard melakukan soft delete VM.');
        $vm->delete();

        return back()->with('status', 'VM berhasil di-soft delete.');
    }

    public function assignVm(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk assign VM.');

        if (! $this->canAssignVm($vm)) {
            $this->audit($user?->id, $vm->id, 'dashboard.vm.assignment.blocked', 'Assignment VM diblokir karena VM dilindungi atau sudah dihapus.', [
                'system_vm' => $vm->isSystemVm(),
                'critical' => $vm->isCritical(),
                'trashed' => $vm->trashed(),
            ]);

            return back()->with('error', 'VM system, critical, atau soft-deleted tidak bisa diassign.');
        }

        $data = $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', $this->studentRoles())),
            ],
        ]);

        $previousOwnerId = $vm->user_id;
        $metadata = $vm->metadata ?? [];
        $metadata['managed_assignment'] = true;
        $metadata['assigned_by_user_id'] = $user?->id;
        $metadata['assigned_at'] = now()->toISOString();

        $vm->update([
            'user_id' => $data['student_id'],
            'metadata' => $metadata,
        ]);

        $this->audit($user?->id, $vm->id, 'dashboard.vm.assigned', 'Dashboard mengassign VM praktikum ke siswa.', [
            'previous_user_id' => $previousOwnerId,
            'assigned_user_id' => $data['student_id'],
        ]);

        return back()->with('status', 'VM berhasil diassign ke siswa.');
    }

    public function unassignVm(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk unassign VM.');

        if (! $this->canAssignVm($vm)) {
            $this->audit($user?->id, $vm->id, 'dashboard.vm.unassignment.blocked', 'Unassign VM diblokir karena VM dilindungi atau sudah dihapus.', [
                'system_vm' => $vm->isSystemVm(),
                'critical' => $vm->isCritical(),
                'trashed' => $vm->trashed(),
            ]);

            return back()->with('error', 'VM system, critical, atau soft-deleted tidak bisa di-unassign.');
        }

        $previousOwnerId = $vm->user_id;
        $metadata = $vm->metadata ?? [];
        unset($metadata['assigned_by_user_id']);
        $metadata['managed_assignment'] = false;
        $metadata['unassigned_by_user_id'] = $user?->id;
        $metadata['unassigned_at'] = now()->toISOString();

        $vm->update([
            'user_id' => null,
            'metadata' => $metadata,
        ]);

        $this->audit($user?->id, $vm->id, 'dashboard.vm.unassigned', 'Dashboard melepas assignment VM praktikum dari siswa.', [
            'previous_user_id' => $previousOwnerId,
        ]);

        return back()->with('status', 'VM berhasil di-unassign.');
    }

    public function bulkGenerateManagedVms(Request $request): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk bulk generate VM.');

        $data = $request->validate([
            'source_vm_id' => ['required', 'integer', 'exists:vms,id'],
            'target_mode' => ['required', Rule::in(['all', 'selected'])],
            'student_ids' => ['array'],
            'student_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', $this->studentRoles())),
            ],
            'confirm_duplicates' => ['nullable', 'boolean'],
        ]);

        $sourceVm = Vm::query()->findOrFail($data['source_vm_id']);

        if (! $sourceVm->isUsableManagedTemplate()) {
            $this->audit($user?->id, $sourceVm->id, 'dashboard.vm.bulk_generation.blocked', 'Bulk generation diblokir karena source VM bukan template praktikum yang aman.', [
                'system_vm' => $sourceVm->isSystemVm(),
                'critical' => $sourceVm->isCritical(),
                'usable_template' => (bool) ($sourceVm->metadata['usable_template'] ?? $sourceVm->metadata['managed_template'] ?? false),
            ]);

            return back()->withInput()->with('error', 'Source VM system/critical hanya boleh diclone jika metadata usable_template atau managed_template bernilai true.');
        }

        $students = $this->targetStudentsForBulkGeneration($data);

        if ($students->isEmpty()) {
            return back()->withInput()->with('error', 'Pilih minimal satu siswa untuk bulk generation.');
        }

        $sourceTemplateVmid = $sourceVm->proxmoxVmid();

        if ($sourceTemplateVmid === null) {
            return back()->withInput()->with('error', 'Source template VM belum memiliki VMID Proxmox.');
        }

        $duplicates = $this->duplicateManagedAssignments($students, $sourceTemplateVmid);

        if ($duplicates->isNotEmpty() && ! $request->boolean('confirm_duplicates')) {
            $names = $duplicates
                ->map(fn (Vm $vm) => $vm->user?->name ?? 'user_id='.$vm->user_id)
                ->unique()
                ->implode(', ');

            return back()
                ->withInput()
                ->with('error', 'Managed VM untuk source template ini sudah ada: '.$names.'. Centang confirm duplicates untuk membuat lagi.');
        }

        $created = collect();
        $failed = collect();

        foreach ($students as $student) {
            $vmName = $this->managedVmName($student, $sourceVm);
            $clone = $this->proxmox->cloneManagedVmFromVm($sourceVm, $vmName);

            if (! ($clone['success'] ?? false)) {
                $failed->push($student->name);
                $this->audit($user?->id, $sourceVm->id, 'dashboard.vm.bulk_generation.clone_failed', 'Clone managed practical VM gagal.', [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'proxmox' => $clone,
                ]);

                continue;
            }

            $vm = $student->vms()->create([
                'name' => $vmName,
                'lab_template_id' => $sourceVm->lab_template_id,
                'vm_template_id' => $sourceVm->vm_template_id,
                'proxmox_id' => (string) $clone['proxmox_id'],
                'node' => $clone['node'],
                'status' => $clone['local_status'] ?? 'stopped',
                'cpu_cores' => $sourceVm->cpu_cores,
                'memory_mb' => $sourceVm->memory_mb,
                'disk_gb' => $sourceVm->disk_gb,
                'metadata' => [
                    ...$this->sshMetadataFromVm($sourceVm),
                    ...$this->sshMetadataFromClone($clone),
                    'vmid' => $clone['vmid'] ?? null,
                    'managed_assignment' => true,
                    'source_template_vm_id' => $sourceVm->id,
                    'source_template_vmid' => $sourceTemplateVmid,
                    'provisioning' => 'managed-template-clone',
                    'task_upid' => $clone['task_upid'] ?? null,
                    'assigned_by_user_id' => $user?->id,
                    'assigned_at' => now()->toISOString(),
                ],
            ]);

            $this->detectAndStoreSshMetadata($vm);
            $created->push($vm);
        }

        $this->audit($user?->id, $sourceVm->id, 'dashboard.vm.bulk_generation.completed', 'Dashboard bulk generate managed practical VM.', [
            'source_template_vmid' => $sourceTemplateVmid,
            'created_vm_ids' => $created->pluck('id')->all(),
            'failed_students' => $failed->all(),
        ]);

        $message = $created->count().' managed practical VM berhasil dibuat.';

        if ($failed->isNotEmpty()) {
            return back()->with('error', $message.' Gagal untuk: '.$failed->implode(', '));
        }

        return back()->with('status', $message);
    }

    public function markSharedPractical(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk mengubah shared practical VM.');

        if (! $this->canSharePracticalVm($vm)) {
            $this->audit($user?->id, $vm->id, 'dashboard.vm.shared_practical.blocked', 'Mark shared practical VM diblokir.', [
                'system_vm' => $vm->isSystemVm(),
                'critical' => $vm->isCritical(),
                'trashed' => $vm->trashed(),
            ]);

            return back()->with('error', 'VM system, critical, atau soft-deleted tidak bisa dijadikan shared practical.');
        }

        $vm->update([
            'metadata' => [
                ...($vm->metadata ?? []),
                'shared_practical' => true,
            ],
        ]);

        $this->audit($user?->id, $vm->id, 'dashboard.vm.shared_practical.marked', 'VM ditandai sebagai shared practical.');

        return back()->with('status', 'VM ditandai sebagai shared practical.');
    }

    public function unmarkSharedPractical(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk mengubah shared practical VM.');

        if ($vm->trashed() || $vm->isProtectedVm()) {
            return back()->with('error', 'VM system, critical, atau soft-deleted tidak bisa diubah.');
        }

        $metadata = $vm->metadata ?? [];
        $metadata['shared_practical'] = false;

        $vm->update(['metadata' => $metadata]);
        $vm->practicalAccesses()->delete();

        $this->audit($user?->id, $vm->id, 'dashboard.vm.shared_practical.unmarked', 'Shared practical VM dinonaktifkan dan akses siswa dicabut.');

        return back()->with('status', 'Shared practical VM dinonaktifkan.');
    }

    public function grantPracticalAccess(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk grant shared VM.');

        if (! $this->canSharePracticalVm($vm)) {
            return back()->with('error', 'VM system, critical, atau soft-deleted tidak bisa dishare.');
        }

        $data = $this->validatePracticalAccessRequest($request);
        $students = $this->targetStudentsForAccess($data);

        if ($students->isEmpty()) {
            return back()->withInput()->with('error', 'Pilih minimal satu siswa.');
        }

        if (! $vm->isSharedPractical()) {
            $vm->update([
                'metadata' => [
                    ...($vm->metadata ?? []),
                    'shared_practical' => true,
                ],
            ]);
        }

        foreach ($students as $student) {
            PracticalVmAccess::updateOrCreate(
                [
                    'vm_id' => $vm->id,
                    'user_id' => $student->id,
                ],
                ['assigned_by' => $user?->id],
            );
        }

        $this->audit($user?->id, $vm->id, 'dashboard.vm.shared_access.granted', 'Akses shared practical VM diberikan ke siswa.', [
            'student_ids' => $students->pluck('id')->all(),
        ]);

        return back()->with('status', 'Akses shared practical diberikan ke '.$students->count().' siswa.');
    }

    public function revokePracticalAccess(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses untuk revoke shared VM.');

        $data = $this->validatePracticalAccessRequest($request);
        $students = $this->targetStudentsForAccess($data);

        if ($students->isEmpty()) {
            return back()->withInput()->with('error', 'Pilih minimal satu siswa.');
        }

        $deleted = $vm->practicalAccesses()
            ->whereIn('user_id', $students->pluck('id'))
            ->delete();

        $this->audit($user?->id, $vm->id, 'dashboard.vm.shared_access.revoked', 'Akses shared practical VM dicabut dari siswa.', [
            'student_ids' => $students->pluck('id')->all(),
            'deleted' => $deleted,
        ]);

        return back()->with('status', 'Akses shared practical dicabut dari '.$deleted.' siswa.');
    }

    public function updateVmSshMetadata(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses ke konfigurasi SSH VM.');

        $data = $request->validate([
            'ssh_host' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'private_ip' => ['nullable', 'string', 'max:255'],
            'public_ip' => ['nullable', 'string', 'max:255'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ssh_username' => ['nullable', 'string', 'max:255'],
            'ssh_password' => ['nullable', 'string', 'max:4096'],
            'ssh_private_key' => ['nullable', 'string', 'max:20000'],
        ]);

        $metadata = $vm->metadata ?? [];

        foreach (['ssh_host', 'ip_address', 'private_ip', 'public_ip', 'ssh_port', 'ssh_username', 'ssh_password', 'ssh_private_key'] as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $value = $data[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if (in_array($key, ['ssh_password', 'ssh_private_key'], true)) {
                    continue;
                }

                unset($metadata[$key]);

                continue;
            }

            $metadata[$key] = $value;
        }

        $vm->update(['metadata' => $metadata]);

        $this->audit($user?->id, $vm->id, 'dashboard.vm.ssh_metadata.updated', 'Dashboard memperbarui metadata SSH VM.', [
            'updated_keys' => array_keys($data),
            'ssh_host_configured' => $vm->refresh()->hasSshMetadata(),
            'secrets_updated' => [
                'ssh_password' => $request->filled('ssh_password'),
                'ssh_private_key' => $request->filled('ssh_private_key'),
            ],
        ]);

        return back()->with('status', 'SSH metadata VM berhasil diperbarui.');
    }

    public function refreshVmSshMetadata(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($this->roleFor($user) === 'admin', 403, 'Anda tidak memiliki akses ke konfigurasi SSH VM.');

        $vmid = $vm->proxmoxVmid();

        if ($vmid === null) {
            return back()->with('error', 'VM belum memiliki VMID Proxmox.');
        }

        $ip = $this->proxmox->detectGuestIpv4($vm->node, $vmid);

        if ($ip === null) {
            $this->audit($user?->id, $vm->id, 'dashboard.vm.ssh_metadata.refresh.failed', 'Dashboard gagal mendeteksi IP SSH VM.', [
                'node' => $vm->node,
                'vmid' => $vmid,
            ]);

            return back()->with('error', 'IP SSH belum terdeteksi dari guest agent.');
        }

        $vm->forceFill([
            'metadata' => [
                ...($vm->metadata ?? []),
                'ssh_host' => $ip,
                'ip_address' => $ip,
            ],
        ])->save();

        $this->audit($user?->id, $vm->id, 'dashboard.vm.ssh_metadata.refresh.success', 'Dashboard mendeteksi IP SSH VM dari guest agent.', [
            'node' => $vm->node,
            'vmid' => $vmid,
            'ssh_host_configured' => true,
        ]);

        return back()->with('status', 'SSH metadata VM berhasil direfresh.');
    }

    public function proxmoxVmAction(Request $request, string $node, string $vmid, string $action): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        $role = $this->roleFor($user);

        if ($role !== 'admin') {
            $this->audit($user?->id, null, 'dashboard.proxmox.vm.denied', 'Action VM dashboard ditolak untuk role non-admin.', [
                'node' => $node,
                'vmid' => $vmid,
                'action' => $action,
                'role' => $role,
            ]);

            abort_if($request->expectsJson(), 403, 'Anda tidak memiliki akses untuk mengontrol VM dari dashboard admin.');

            return back()->with('error', 'Student tidak bisa mengontrol VM dari dashboard admin.');
        }

        $validated = Validator::make([
            'node' => $node,
            'vmid' => $vmid,
            'action' => $action,
        ], [
            'node' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'vmid' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:start,stop,shutdown'],
        ])->validate();

        $vmid = (int) $validated['vmid'];
        $localVm = $this->findLocalVm($validated['node'], $vmid);

        if (! $this->canAccessRealVm($user, $localVm)) {
            // VM Proxmox nyata harus cocok dengan record lokal agar ownership tetap terisolasi.
            $this->audit($user?->id, $localVm?->id, 'dashboard.proxmox.vm.denied', 'Akses VM ditolak karena ownership.', [
                'node' => $validated['node'],
                'vmid' => $vmid,
                'action' => $validated['action'],
                'role' => $this->roleFor($user),
            ]);

            abort_if($request->expectsJson(), 403, 'Anda tidak memiliki akses ke VM ini.');

            return back()->with('error', 'Anda tidak memiliki akses ke VM ini.');
        }

        if ($this->isProtectedVm($validated['node'], $vmid, $localVm)) {
            // VM critical/system dilindungi dari action lifecycle dashboard walau terlihat oleh admin.
            $this->audit($user?->id, $localVm?->id, 'dashboard.proxmox.vm.critical_blocked', 'Action ke VM kritikal diblokir.', [
                'node' => $validated['node'],
                'vmid' => $vmid,
                'action' => $validated['action'],
                'critical' => $this->metadataFlag($localVm, 'critical'),
                'system_vm' => $this->metadataFlag($localVm, 'system_vm'),
            ]);

            return back()->with('error', 'VM kritikal atau system tidak boleh dikontrol dari dashboard.');
        }

        $response = match ($validated['action']) {
            'start' => $this->proxmox->startVmById($validated['node'], $validated['vmid']),
            'stop' => $this->proxmox->stopVmById($validated['node'], $validated['vmid']),
            'shutdown' => $this->proxmox->shutdownVmById($validated['node'], $validated['vmid']),
        };

        $auditAction = ($response['success'] ?? false)
            ? 'dashboard.proxmox.vm.'.$validated['action'].'.success'
            : 'dashboard.proxmox.vm.'.$validated['action'].'.failed';

        // Setiap action Proxmox dicatat bersama hasil API untuk akuntabilitas operasional.
        $this->audit($user?->id, $localVm?->id, $auditAction, 'Dashboard menjalankan action VM real Proxmox.', [
            'node' => $validated['node'],
            'vmid' => $vmid,
            'action' => $validated['action'],
            'success' => $response['success'] ?? false,
            'status' => $response['status'] ?? null,
            'message' => $response['message'] ?? null,
            'upid' => $response['data'] ?? null,
        ]);

        if (! ($response['success'] ?? false)) {
            return back()->with('error', 'Action VM gagal: '.($response['message'] ?? 'Proxmox API request gagal.'));
        }

        return back()->with('status', 'Action '.$validated['action'].' dikirim ke VM '.$validated['vmid'].' pada node '.$validated['node'].'.');
    }

    private function view(string $section): View
    {
        $user = $this->resolveDashboardUser(request());
        $localVms = Vm::query()->with(['user', 'labTemplate', 'vmTemplate', 'practicalAccesses.user'])->latest()->get();
        $visibleLocalVms = $this->localVmsForUser($user);
        $realVmResponse = $this->proxmox->listVms();
        $realVms = collect($realVmResponse['data'] ?? [])
            ->filter(fn ($vm) => is_array($vm))
            ->map(fn (array $vm) => $this->formatRealVm($vm, $user, $localVms))
            ->filter(fn (array $vm) => $vm['can_view'])
            ->filter(fn (array $vm) => $this->roleFor($user) !== 'student' || (! $vm['is_system_vm'] && ! $vm['is_critical']))
            ->values();
        $role = $this->roleFor($user);
        $canViewPamMonitoring = $role === 'admin';
        $vmTemplates = VmTemplate::query()
            ->when($role === 'student', fn ($query) => $query->enabled())
            ->orderBy('name')
            ->get();

        // Data SOC dipasang hanya saat admin melihat dashboard agar telemetry PAM tidak bocor ke siswa.
        $data = [
            'section' => $section,
            'currentUser' => $user,
            'templates' => $vmTemplates,
            'vms' => $visibleLocalVms,
            'realVmResponse' => $realVmResponse,
            'realVms' => $realVms,
            'vmTemplates' => $vmTemplates,
            'students' => $this->assignableStudents(),
            'sourceTemplateVms' => $this->sourceTemplateVms(),
            'auditLogs' => $this->dashboardAuditLogs($user, $role),
            'canViewPamMonitoring' => $canViewPamMonitoring,
            'recentCommandLogs' => $canViewPamMonitoring ? $this->recentCommandLogs() : collect(),
            'activeTerminalSessions' => $canViewPamMonitoring ? $this->activeTerminalSessions() : collect(),
            'blockedCommandLogs' => $canViewPamMonitoring ? $this->blockedCommandLogs() : collect(),
            'terminalActivityTimeline' => $canViewPamMonitoring ? $this->terminalActivityTimeline() : collect(),
            'stats' => [
                'templates' => VmTemplate::count(),
                'vms' => $realVms->isNotEmpty() ? $realVms->count() : $visibleLocalVms->count(),
                'auditLogs' => $role === 'student' && $user ? AuditLog::where('user_id', $user->id)->count() : AuditLog::count(),
                'users' => User::count(),
            ],
        ];

        if ($section === 'overview' && $this->roleFor($user) === 'student') {
            return view('dashboard.student', $data);
        }

        if ($section === 'audit-logs') {
            return view('dashboard.audit-logs', $data);
        }

        if ($section === 'soc') {
            return view('dashboard.soc', $data);
        }

        return view('dashboard.index', $data);
    }

    private function localVmsForUser(?User $user): Collection
    {
        $query = Vm::query()
            ->with(['user', 'labTemplate', 'vmTemplate', 'practicalAccesses.user'])
            ->latest();

        // Isolasi VM lokal: siswa hanya melihat resource miliknya, admin untuk supervisi.
        return match ($this->roleFor($user)) {
            'admin' => $query->get(),
            'student' => $user
                ? $query
                    ->where(fn ($query) => $query
                        ->where('user_id', $user->id)
                        ->orWhereHas('practicalAccesses', fn ($accessQuery) => $accessQuery->where('user_id', $user->id)))
                    ->get()
                    ->filter(fn (Vm $vm) => $vm->isStudentVisible())
                    ->values()
                : collect(),
            default => collect(),
        };
    }

    private function dashboardAuditLogs(?User $user, string $role): Collection
    {
        if ($role === 'student') {
            return $user ? $this->studentAuditLogs($user, 50) : collect();
        }

        return AuditLog::with(['user', 'vm'])->latest()->limit(50)->get();
    }

    private function studentAuditLogs(User $user, int $limit): Collection
    {
        return AuditLog::with(['vm'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function studentCommandLogs(User $user, int $limit): Collection
    {
        return CommandLog::with(['vm', 'terminalSession'])
            ->forUser($user)
            ->recent()
            ->limit($limit)
            ->get();
    }

    private function studentActivityTimeline(Collection $auditLogs, Collection $commandLogs): Collection
    {
        $auditItems = $auditLogs->map(fn (AuditLog $log): array => [
            'type' => $log->action,
            'title' => $this->readableActivityTitle($log->action),
            'description' => $log->description,
            'vm_name' => $log->vm?->name ?? 'Virtual lab workspace',
            'occurred_at' => $log->created_at,
            'status' => $this->activityStatusFor($log->action),
            'icon' => $this->activityIconFor($log->action),
        ]);

        $commandItems = $commandLogs->map(fn (CommandLog $log): array => [
            'type' => $log->isBlocked() ? 'terminal.command.blocked' : 'terminal.command.executed',
            'title' => $log->isBlocked() ? 'Command blocked' : 'Command executed',
            'description' => $log->isBlocked()
                ? ($log->blocked_reason ?: 'Restricted command was blocked by policy.')
                : 'Ran '.$this->shortCommand($log->command),
            'vm_name' => $log->vm?->name ?? 'Virtual lab workspace',
            'occurred_at' => $log->executed_at,
            'status' => $log->status->value,
            'icon' => $log->isBlocked() ? 'blocked' : 'command',
        ]);

        return $auditItems
            ->merge($commandItems)
            ->sortByDesc('occurred_at')
            ->take(30)
            ->values();
    }

    private function readableActivityTitle(string $action): string
    {
        return [
            'terminal.session.created' => 'Terminal session started',
            'terminal.session.closed' => 'Terminal session closed',
            'terminal.session.denied' => 'Terminal session denied',
            'terminal_session.revoked' => 'Terminal session ended',
            'terminal.command.executed' => 'Command executed',
            'terminal.command.blocked' => 'Command blocked',
            'student.vm.created' => 'VM created',
            'student.vm.start.success' => 'VM started',
            'student.vm.start.failed' => 'VM start failed',
            'student.vm.stop.success' => 'VM stopped',
            'student.vm.stop.failed' => 'VM stop failed',
            'student.vm.shutdown.success' => 'VM shut down',
            'student.vm.shutdown.failed' => 'VM shutdown failed',
            'student.vm.deleted.success' => 'VM deleted',
            'student.vm.deleted.failed' => 'VM delete failed',
            'student.vm.provisioned' => 'VM provisioned',
            'student.vm.provision_failed' => 'VM provisioning failed',
            'dashboard.vm.shared_access.granted' => 'Lab access granted',
            'dashboard.vm.shared_access.revoked' => 'Lab access revoked',
        ][$action] ?? Str::of($action)->replace(['.', '_'], ' ')->title()->toString();
    }

    private function activityStatusFor(string $action): string
    {
        if (str_contains($action, 'blocked') || str_contains($action, 'denied') || str_ends_with($action, '.failed')) {
            return 'blocked';
        }

        if (str_contains($action, 'revoked') || str_contains($action, 'deleted')) {
            return 'ended-student';
        }

        if (str_contains($action, 'shutdown') || str_contains($action, 'stop')) {
            return 'stopped';
        }

        return 'succeeded';
    }

    private function activityIconFor(string $action): string
    {
        if (str_contains($action, 'command')) {
            return str_contains($action, 'blocked') ? 'blocked' : 'command';
        }

        if (str_contains($action, 'session')) {
            return 'terminal';
        }

        if (str_contains($action, 'shared_access')) {
            return 'access';
        }

        return 'vm';
    }

    private function shortCommand(string $command): string
    {
        return Str::of($command)->squish()->limit(80)->toString();
    }

    private function formatRealVm(array $vm, ?User $user, Collection $localVms): array
    {
        $vmid = (int) ($vm['vmid'] ?? 0);
        $node = (string) ($vm['node'] ?? '-');
        $localVm = $this->findLocalVmInCollection($localVms, $node, $vmid);
        $canAccess = $this->canAccessRealVm($user, $localVm);
        $systemVm = $localVm?->isSystemVm() ?? in_array($vmid, Vm::INFRASTRUCTURE_VMIDS, true);
        $critical = $this->isCriticalVm($node, $vmid, $vm['name'] ?? null, $localVm);
        $protected = $systemVm || $critical;

        // Gabungkan inventory Proxmox dengan ownership lokal sebelum menentukan view/control permission.
        return [
            'vmid' => $vmid,
            'name' => $localVm?->name ?? $vm['name'] ?? '-',
            'node' => $node,
            'status' => $vm['status'] ?? 'unknown',
            'cpu' => $this->formatCpu($vm['cpu'] ?? null),
            'memory_usage' => $this->formatBytes($vm['mem'] ?? null),
            'max_memory' => $this->formatBytes($vm['maxmem'] ?? null),
            'disk' => $this->formatBytes($vm['disk'] ?? null),
            'uptime' => $this->formatUptime($vm['uptime'] ?? null),
            'local_vm_id' => $localVm?->id,
            'owner_user_id' => $localVm?->user_id,
            'owner_name' => $systemVm ? 'System VM' : $localVm?->user?->name,
            'ownership_status' => $systemVm ? 'system' : ($localVm ? 'owned' : 'unassigned'),
            'can_view' => $canAccess,
            'can_control' => $canAccess && ! $protected,
            'is_critical' => $critical,
            'is_system_vm' => $systemVm,
        ];
    }

    private function recentCommandLogs(): Collection
    {
        return CommandLog::with(['user', 'vm', 'terminalSession'])
            ->recent()
            ->limit(10)
            ->get()
            ->map(fn (CommandLog $log) => $this->formatCommandLog($log));
    }

    private function activeTerminalSessions(): Collection
    {
        // SOC menampilkan hanya sesi aktif yang belum melewati waktu kedaluwarsa.
        return TerminalSession::with(['user', 'vm'])
            ->active()
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->latest('last_activity_at')
            ->limit(10)
            ->get()
            ->map(fn (TerminalSession $session) => $this->formatTerminalSession($session));
    }

    private function blockedCommandLogs(): Collection
    {
        // Command blocked disorot karena menjadi indikator percobaan melanggar policy terminal.
        return CommandLog::with(['user', 'vm', 'terminalSession'])
            ->blocked()
            ->recent()
            ->limit(10)
            ->get()
            ->map(fn (CommandLog $log) => $this->formatCommandLog($log));
    }

    private function terminalActivityTimeline(): Collection
    {
        // Timeline menyatukan lifecycle sesi dan command agar investigator melihat urutan kejadian.
        $sessions = TerminalSession::with(['user', 'vm'])
            ->latest('started_at')
            ->limit(10)
            ->get()
            ->flatMap(function (TerminalSession $session): array {
                $items = [
                    [
                        'type' => 'session_started',
                        'label' => 'Session started',
                        'style' => 'badge-allowed',
                        'user_name' => $session->user?->name ?? '-',
                        'user_email' => $session->user?->email ?? '',
                        'vm_name' => $session->vm?->name ?? '-',
                        'session_uuid' => $session->session_uuid,
                        'description' => 'Terminal session opened',
                        'occurred_at' => $session->started_at,
                    ],
                ];

                if ($session->ended_at !== null) {
                    $items[] = [
                        'type' => 'session_'.$session->status->value,
                        'label' => 'Session '.$session->status->value,
                        'style' => $this->terminalStatusBadgeClass($session->status->value),
                        'user_name' => $session->user?->name ?? '-',
                        'user_email' => $session->user?->email ?? '',
                        'vm_name' => $session->vm?->name ?? '-',
                        'session_uuid' => $session->session_uuid,
                        'description' => 'Terminal session '.$session->status->value,
                        'occurred_at' => $session->ended_at,
                    ];
                }

                return $items;
            });

        $commands = CommandLog::with(['user', 'vm', 'terminalSession'])
            ->recent()
            ->limit(20)
            ->get()
            ->map(fn (CommandLog $log) => [
                'type' => $log->isBlocked() ? 'command_blocked' : 'command_recorded',
                'label' => $log->isBlocked() ? 'Command blocked' : 'Command '.$log->status->value,
                'style' => $this->commandStatusBadgeClass($log->status->value),
                'user_name' => $log->user?->name ?? '-',
                'user_email' => $log->user?->email ?? '',
                'vm_name' => $log->vm?->name ?? '-',
                'session_uuid' => $log->terminalSession?->session_uuid,
                'description' => $log->command,
                'occurred_at' => $log->executed_at ?? $log->created_at,
            ]);

        return $sessions
            ->merge($commands)
            ->filter(fn (array $item) => $item['occurred_at'] !== null)
            ->sortByDesc('occurred_at')
            ->take(15)
            ->values();
    }

    private function formatCommandLog(CommandLog $log): array
    {
        return [
            'user_name' => $log->user?->name ?? '-',
            'user_email' => $log->user?->email ?? '',
            'vm_name' => $log->vm?->name ?? '-',
            'session_uuid' => $log->terminalSession?->session_uuid,
            'command' => $log->command,
            'status' => $log->status->value,
            'status_class' => $this->commandStatusBadgeClass($log->status->value),
            'blocked_reason' => $log->blocked_reason,
            'executed_at' => $log->executed_at ?? $log->created_at,
            'output_excerpt' => $this->safeCommandOutputExcerpt($log),
        ];
    }

    private function formatTerminalSession(TerminalSession $session): array
    {
        return [
            'user_name' => $session->user?->name ?? '-',
            'user_email' => $session->user?->email ?? '',
            'vm_name' => $session->vm?->name ?? '-',
            'id' => $session->id,
            'session_uuid' => $session->session_uuid,
            'ssh_host' => $session->ssh_host,
            'ssh_port' => $session->ssh_port,
            'ssh_username' => $session->ssh_username,
            'status' => $session->status->value,
            'status_class' => $this->terminalStatusBadgeClass($session->status->value),
            'can_revoke' => $session->isActive(),
            'started_at' => $session->started_at,
            'expires_at' => $session->expires_at,
            'last_activity_at' => $session->last_activity_at,
        ];
    }

    private function commandStatusBadgeClass(string $status): string
    {
        return match ($status) {
            CommandLogStatus::Succeeded->value => 'badge-succeeded',
            CommandLogStatus::Failed->value => 'badge-failed',
            CommandLogStatus::Blocked->value => 'badge-blocked',
            CommandLogStatus::Allowed->value => 'badge-allowed',
            default => 'badge-other',
        };
    }

    private function terminalStatusBadgeClass(string $status): string
    {
        return match ($status) {
            TerminalSessionStatus::Active->value => 'badge-succeeded',
            TerminalSessionStatus::Expired->value => 'badge-blocked',
            TerminalSessionStatus::Revoked->value => 'badge-failed',
            TerminalSessionStatus::Closed->value => 'badge-closed',
            default => 'badge-allowed',
        };
    }

    private function safeCommandOutputExcerpt(CommandLog $log): ?string
    {
        $output = $log->output_excerpt;

        if ($output === null || $output === '') {
            return null;
        }

        $excerpt = str($output)
            ->replace("\0", '')
            ->limit(600, "\n[output truncated]")
            ->toString();
        $sshPassword = config('services.terminal.ssh_password');

        foreach ([$sshPassword, $log->vm?->sshPassword(), $log->vm?->sshPrivateKey()] as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            // Output SOC tidak boleh membocorkan password SSH yang mungkin ikut tercetak command.
            $excerpt = str_replace($secret, '[redacted]', $excerpt);
        }

        return $excerpt;
    }

    private function findLocalVm(string $node, int $vmid): ?Vm
    {
        return $this->findLocalVmInCollection(
            Vm::query()->with('user')->get(),
            $node,
            $vmid,
        );
    }

    private function findLocalVmInCollection(Collection $localVms, string $node, int $vmid): ?Vm
    {
        return $localVms
            ->filter(fn (Vm $vm) => ! $vm->trashed() && $vm->matchesProxmoxVm($node, $vmid))
            ->sortByDesc(fn (Vm $vm) => $vm->created_at?->timestamp ?? 0)
            ->first();
    }

    private function canAccessRealVm(?User $user, ?Vm $localVm): bool
    {
        $role = $this->roleFor($user);

        if ($role === 'admin') {
            return true;
        }

        if ($role === 'student') {
            // Siswa hanya bisa mengontrol VM Proxmox yang terikat ke record lokal miliknya.
            return $user !== null
                && $localVm !== null
                && ($localVm->user_id === $user->id || $localVm->hasPracticalAccess($user));
        }

        return false;
    }

    private function roleFor(?User $user): string
    {
        return match ($user?->role) {
            'admin' => 'admin',
            'student' => 'student',
            default => 'guest',
        };
    }

    private function canAssignVm(Vm $vm): bool
    {
        return ! $vm->trashed() && ! $vm->isSystemVm() && ! $vm->isCritical();
    }

    private function canSharePracticalVm(Vm $vm): bool
    {
        return ! $vm->trashed() && ! $vm->isProtectedVm();
    }

    private function assignableStudents(): Collection
    {
        return User::query()
            ->whereIn('role', $this->studentRoles())
            ->orderBy('name')
            ->get();
    }

    private function sourceTemplateVms(): Collection
    {
        return Vm::query()
            ->with('user')
            ->whereNull('deleted_at')
            ->latest()
            ->get()
            ->filter(fn (Vm $vm) => $vm->isUsableManagedTemplate())
            ->values();
    }

    private function targetStudentsForBulkGeneration(array $data): Collection
    {
        $query = User::query()
            ->whereIn('role', $this->studentRoles())
            ->orderBy('name');

        if (($data['target_mode'] ?? null) === 'selected') {
            $ids = collect($data['student_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return collect();
            }

            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    private function validatePracticalAccessRequest(Request $request): array
    {
        return $request->validate([
            'target_mode' => ['required', Rule::in(['all', 'selected'])],
            'student_ids' => ['array'],
            'student_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', $this->studentRoles())),
            ],
        ]);
    }

    private function targetStudentsForAccess(array $data): Collection
    {
        $query = User::query()
            ->whereIn('role', $this->studentRoles())
            ->orderBy('name');

        if (($data['target_mode'] ?? null) === 'selected') {
            $ids = collect($data['student_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return collect();
            }

            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    private function duplicateManagedAssignments(Collection $students, int $sourceTemplateVmid): Collection
    {
        return Vm::query()
            ->with('user')
            ->whereIn('user_id', $students->pluck('id'))
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (Vm $vm) => $vm->isManagedAssignment()
                && (int) ($vm->metadata['source_template_vmid'] ?? 0) === $sourceTemplateVmid)
            ->values();
    }

    private function managedVmName(User $student, Vm $sourceVm): string
    {
        $username = Str::of($student->email)
            ->before('@')
            ->slug('-')
            ->limit(24, '')
            ->toString();
        $templateName = Str::of($sourceVm->name)
            ->slug('-')
            ->limit(28, '')
            ->toString();

        return Str::limit('practical-'.$username.'-'.$templateName, 64, '');
    }

    private function detectAndStoreSshMetadata(Vm $vm): bool
    {
        if ($vm->status !== 'running') {
            return false;
        }

        $vmid = $vm->proxmoxVmid();

        if ($vmid === null) {
            return false;
        }

        $ip = $this->proxmox->detectGuestIpv4($vm->node, $vmid);

        if ($ip === null) {
            return false;
        }

        $vm->forceFill([
            'metadata' => [
                ...($vm->metadata ?? []),
                'ssh_host' => $ip,
                'ip_address' => $ip,
                'ssh_port' => $vm->metadata['ssh_port'] ?? 22,
            ],
        ])->save();

        return true;
    }

    private function sshMetadataFromVm(Vm $vm): array
    {
        $metadata = [];

        foreach (['ssh_port', 'ssh_username', 'ssh_password', 'ssh_private_key'] as $key) {
            if (! array_key_exists($key, $vm->metadata ?? [])) {
                continue;
            }

            $metadata[$key] = $vm->metadata[$key];
        }

        return $metadata;
    }

    private function sshMetadataFromClone(array $clone): array
    {
        $metadata = [];

        foreach (['ssh_host', 'ssh_port', 'ssh_username', 'ssh_password', 'ssh_private_key', 'ip_address', 'private_ip', 'public_ip'] as $key) {
            if (! array_key_exists($key, $clone)) {
                continue;
            }

            $value = $clone[$key];

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                continue;
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    private function studentRoles(): array
    {
        return ['student'];
    }

    private function resolveDashboardUser(Request $request): ?User
    {
        if ($request->user()) {
            return $request->user();
        }

        $email = $request->headers->get('Cf-Access-Authenticated-User-Email')
            ?: $request->headers->get('X-Forwarded-Email');

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
                'password' => bcrypt(str()->random(40)),
                'role' => 'student',
            ],
        );
    }

    private function isProtectedVm(string $node, int $vmid, ?Vm $localVm): bool
    {
        // Perlindungan VM memakai metadata lokal dan heuristic VM critical bawaan lab.
        return ($localVm?->isSystemVm() ?? in_array($vmid, Vm::INFRASTRUCTURE_VMIDS, true))
            || $this->isCriticalVm($node, $vmid, $localVm?->name, $localVm);
    }

    private function isCriticalVm(string $node, int $vmid, ?string $name = null, ?Vm $localVm = null): bool
    {
        // Jump server dan VMID khusus dianggap aset infrastruktur, bukan VM praktikum biasa.
        return ($localVm?->isCritical() ?? false)
            || in_array($vmid, [100, 101, 102], true)
            || str($name ?? '')->lower()->contains('jump-server');
    }

    private function metadataFlag(?Vm $vm, string $key): bool
    {
        if (! $vm) {
            return false;
        }

        return filter_var($vm->metadata[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function formatCpu(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        return number_format((float) $value * 100, 2).'%';
    }

    private function formatBytes(mixed $bytes): string
    {
        if (! is_numeric($bytes)) {
            return '-';
        }

        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return number_format($bytes, $i === 0 ? 0 : 2).' '.$units[$i];
    }

    private function formatUptime(mixed $seconds): string
    {
        if (! is_numeric($seconds)) {
            return '-';
        }

        $seconds = (int) $seconds;
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    private function audit(?int $userId, ?int $vmId, string $action, string $description, array $metadata = []): void
    {
        // Audit dashboard menyimpan konteks user, VM, action, dan metadata untuk kebutuhan SOC.
        AuditLog::create([
            'user_id' => $userId,
            'vm_id' => $vmId,
            'action' => $action,
            'description' => $description,
            'metadata' => ['source' => 'web-dashboard', ...$this->redactSensitiveMetadata($metadata)],
        ]);
    }

    private function redactSensitiveMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['ssh_password', 'ssh_private_key'], true)) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->redactSensitiveMetadata($value);
            }
        }

        return $metadata;
    }
}
