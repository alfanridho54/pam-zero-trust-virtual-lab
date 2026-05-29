<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vm;
use App\Models\VmTemplate;
use App\Services\ProxmoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class StudentVmController extends Controller
{
    public function __construct(private readonly ProxmoxService $proxmox) {}

    public function index(Request $request): View
    {
        $user = $this->studentUser($request);

        $vms = Vm::query()
            ->with(['user', 'practicalAccesses'])
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->orWhereHas('practicalAccesses', fn ($accessQuery) => $accessQuery->where('user_id', $user->id)))
            ->studentVisible()
            ->latest()
            ->get()
            ->filter(fn (Vm $vm) => $vm->isStudentVisible())
            ->values();

        $vms->each(fn (Vm $vm) => $this->syncProvisioningStatus($user, $vm));
        $this->syncSharedVmDisplayStatus($user, $vms);

        return view('student.vms.index', [
            'currentUser' => $user,
            'vms' => $vms,
            'maxStudentVms' => $this->maxStudentVms($user),
            'quotaUsedVms' => $this->studentQuotaVmCount($user),
            'vmTemplates' => VmTemplate::query()->enabled()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->studentUser($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*[A-Za-z0-9]$/',
            ],
            'vm_template_id' => [
                'required',
                'integer',
                Rule::exists('vm_templates', 'id')->where('enabled', true),
            ],
        ]);

        $template = VmTemplate::query()->enabled()->findOrFail($data['vm_template_id']);

        if ($this->studentQuotaVmCount($user) >= $this->maxStudentVms($user)) {
            return back()->withInput()->with('error', 'Kuota VM student sudah penuh.');
        }

        $clone = $this->proxmox->cloneStudentVmFromVmTemplate($template, $data['name']);

        if (! ($clone['success'] ?? false)) {
            $this->audit($user, null, 'student.vm.create.failed', 'Clone VM student dari template gagal.', [
                'request' => $data,
                'proxmox' => $clone,
            ]);

            return back()->withInput()->with('error', 'Create VM gagal: '.($clone['message'] ?? 'Proxmox API request gagal.'));
        }

        try {
            $vm = DB::transaction(fn () => $user->vms()->create([
                'name' => $data['name'],
                'vm_template_id' => $template->id,
                'proxmox_id' => (string) $clone['proxmox_id'],
                'node' => $clone['node'],
                'status' => $clone['local_status'] ?? 'stopped',
                'cpu_cores' => $template->cpu,
                'memory_mb' => $template->ram,
                'disk_gb' => $template->disk,
                'metadata' => [
                    'vmid' => $clone['vmid'] ?? null,
                    'source_vm_template_id' => $template->id,
                    'source_template_vmid' => $clone['source_template_vmid'] ?? null,
                    'provisioning' => 'template-clone',
                    'task_upid' => $clone['task_upid'] ?? null,
                    ...$template->sshMetadata(),
                    ...$this->sshMetadataFrom($clone),
                ],
            ]));
        } catch (Throwable $exception) {
            $this->audit($user, null, 'student.vm.provisioning_mismatch', 'Proxmox clone diterima tetapi record VM lokal gagal disimpan.', [
                'request' => $data,
                'proxmox' => $clone,
                'error' => $exception->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Create VM gagal setelah Proxmox menerima clone. Admin perlu rekonsiliasi VMID '.($clone['vmid'] ?? '-').'.');
        }

        $ipDetected = $this->detectAndStoreSshMetadata($vm, $template);

        $this->audit($user, $vm, 'student.vm.created', 'Student membuat VM dari template Proxmox.', [
            'proxmox' => $this->redactSensitiveMetadata($clone),
            'vm_template_id' => $template->id,
            'guest_ipv4_detected' => $ipDetected,
        ]);

        $status = $ipDetected
            ? 'VM berhasil dibuat dari template dan IP SSH terdeteksi.'
            : 'VM berhasil dibuat dari template. IP SSH akan tersedia setelah guest agent siap.';

        return redirect()->route('student.vms.index')->with('status', $status);
    }

    public function action(Request $request, Vm $vm, string $action): RedirectResponse
    {
        $user = $this->studentUser($request);
        $this->guardOwnedStudentVm($user, $vm);

        validator(['action' => $action], [
            'action' => ['required', Rule::in(['start', 'stop', 'shutdown'])],
        ])->validate();

        if ($vm->isProtectedVm()) {
            $this->audit($user, $vm, 'student.vm.critical_blocked', 'Action VM student diblokir untuk VM critical/system.', [
                'action' => $action,
            ]);

            return back()->with('error', 'VM kritikal atau system tidak boleh dikontrol.');
        }

        if ($vm->status === 'provisioning') {
            $this->syncProvisioningStatus($user, $vm);

            if ($vm->refresh()->status === 'provisioning') {
                return back()->with('error', 'VM masih dalam proses clone Proxmox.');
            }
        }

        $vmid = $vm->proxmoxVmid();
        abort_unless($vmid !== null, 422, 'VM belum memiliki VMID Proxmox.');

        $response = match ($action) {
            'start' => $this->proxmox->startVmById($vm->node, $vmid),
            'stop' => $this->proxmox->stopVmById($vm->node, $vmid),
            'shutdown' => $this->proxmox->shutdownVmById($vm->node, $vmid),
        };

        if ($response['success'] ?? false) {
            $vm->update([
                'status' => $action === 'start' ? 'running' : 'stopped',
            ]);

            if ($action === 'start') {
                $this->detectAndStoreSshMetadata($vm->refresh(), $vm->vmTemplate);
            }
        }

        $this->audit($user, $vm, 'student.vm.'.$action.(($response['success'] ?? false) ? '.success' : '.failed'), 'Student menjalankan lifecycle action VM.', [
            'action' => $action,
            'node' => $vm->node,
            'vmid' => $vmid,
            'proxmox' => $response,
        ]);

        if (! ($response['success'] ?? false)) {
            return back()->with('error', 'Action VM gagal: '.($response['message'] ?? 'Proxmox API request gagal.'));
        }

        return back()->with('status', 'Action '.$action.' berhasil dikirim.');
    }

    public function destroy(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->studentUser($request);
        $this->guardOwnedStudentVm($user, $vm);

        if ($vm->isProtectedVm()) {
            $this->audit($user, $vm, 'student.vm.critical_blocked', 'Delete VM student diblokir untuk VM critical/system.', [
                'action' => 'delete',
            ]);

            return back()->with('error', 'VM kritikal atau system tidak boleh dihapus.');
        }

        if ($vm->status === 'running') {
            return back()->with('error', 'Matikan VM sebelum menghapus.');
        }

        $vmid = $vm->proxmoxVmid();
        $response = $vmid === null
            ? ['success' => true, 'message' => 'OK (local delete only)', 'data' => null]
            : $this->proxmox->deleteVmById($vm->node, $vmid);

        $this->audit($user, $vm, 'student.vm.deleted'.(($response['success'] ?? false) ? '.success' : '.failed'), 'Student menghapus VM pribadi.', [
            'node' => $vm->node,
            'vmid' => $vmid,
            'proxmox' => $response,
        ]);

        if (! ($response['success'] ?? false)) {
            return back()->with('error', 'Delete VM gagal: '.($response['message'] ?? 'Proxmox API request gagal.'));
        }

        $vm->update(['status' => 'deleted']);
        $vm->delete();

        return back()->with('status', 'VM berhasil dihapus.');
    }

    private function guardOwnedStudentVm(User $user, Vm $vm): void
    {
        abort_unless($vm->user_id === $user->id, 403, 'Anda tidak memiliki akses ke VM ini.');
    }

    private function studentUser(Request $request): User
    {
        $user = Auth::user() ?: $request->user() ?: $this->resolveCloudflareUser($request);

        abort_unless($user instanceof User, 403, 'Anda harus login sebagai student.');
        abort_unless(in_array($user->role, ['student', 'mahasiswa', 'siswa'], true), 403, 'Halaman ini hanya untuk student.');

        return $user;
    }

    private function resolveCloudflareUser(Request $request): ?User
    {
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

    private function maxStudentVms(User $user): int
    {
        return max(1, (int) ($user->quota?->max_vms ?: config('lab.max_student_vms', 2)));
    }

    private function studentQuotaVmCount(User $user): int
    {
        return $user->vms()
            ->studentVisible()
            ->get()
            ->filter(fn (Vm $vm) => $vm->isStudentVisible() && ! $vm->isManagedAssignment() && ! $vm->isSharedPractical())
            ->count();
    }

    private function syncProvisioningStatus(User $user, Vm $vm): void
    {
        $upid = $vm->metadata['task_upid'] ?? null;

        if ($vm->status !== 'provisioning' || ! is_string($upid) || $upid === '') {
            return;
        }

        $task = $this->proxmox->getTaskStatus($vm->node, $upid);

        if (! ($task['success'] ?? false)) {
            return;
        }

        $data = $task['data'] ?? [];

        if (($data['status'] ?? null) !== 'stopped') {
            return;
        }

        if (($data['exitstatus'] ?? null) === 'OK') {
            $vm->update(['status' => 'stopped']);
            $this->audit($user, $vm, 'student.vm.provisioned', 'Clone VM student selesai.', [
                'task' => $data,
            ]);

            return;
        }

        $vm->update(['status' => 'failed']);
        $this->audit($user, $vm, 'student.vm.provision_failed', 'Clone VM student gagal.', [
            'task' => $data,
        ]);
    }

    private function audit(User $user, ?Vm $vm, string $action, string $description, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'vm_id' => $vm?->id,
            'action' => $action,
            'description' => $description,
            'metadata' => ['source' => 'student-self-service', ...$this->redactSensitiveMetadata($metadata)],
        ]);
    }

    private function syncSharedVmDisplayStatus(User $user, Collection $vms): void
    {
        $sharedVms = $vms
            ->filter(fn (Vm $vm) => $vm->user_id !== $user->id && $vm->hasPracticalAccess($user))
            ->values();

        if ($sharedVms->isEmpty()) {
            return;
        }

        $response = $this->proxmox->listVms();

        if (! ($response['success'] ?? false)) {
            $sharedVms->each(fn (Vm $vm) => $vm->forceFill(['status' => 'unknown']));

            return;
        }

        $realVms = collect($response['data'] ?? [])->filter(fn ($vm) => is_array($vm));

        $sharedVms->each(function (Vm $vm) use ($realVms): void {
            $vmid = $vm->proxmoxVmid();
            $realVm = $vmid === null
                ? null
                : $realVms->first(fn (array $realVm) => (int) ($realVm['vmid'] ?? 0) === $vmid
                    && (string) ($realVm['node'] ?? '') === $vm->node);

            $vm->forceFill([
                'status' => is_array($realVm) ? (string) ($realVm['status'] ?? 'unknown') : 'unknown',
            ]);
        });
    }

    private function detectAndStoreSshMetadata(Vm $vm, ?VmTemplate $template = null): bool
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

        $metadata = $vm->metadata ?? [];
        $metadata['ssh_host'] = $ip;
        $metadata['ip_address'] = $ip;
        $metadata['ssh_port'] = 22;
        $metadata['ssh_username'] = $template?->ssh_username ?: ($metadata['ssh_username'] ?? 'student');

        if (is_string($template?->ssh_password) && $template->ssh_password !== '') {
            $metadata['ssh_password'] = $template->ssh_password;
        }

        $vm->forceFill(['metadata' => $metadata])->save();

        return true;
    }

    private function sshMetadataFrom(array $source): array
    {
        $metadata = [];

        foreach (['ssh_host', 'ssh_port', 'ssh_username', 'ssh_password', 'ssh_private_key', 'ip_address', 'private_ip', 'public_ip'] as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];

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
