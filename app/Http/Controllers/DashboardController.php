<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LabTemplate;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ProxmoxService $proxmox)
    {
    }

    public function index(): View
    {
        return $this->view('overview');
    }

    public function templates(): View
    {
        return $this->view('templates');
    }

    public function vms(): View
    {
        return $this->view('vms');
    }

    public function auditLogs(): View
    {
        return $this->view('audit-logs');
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

    public function proxmoxVmAction(Request $request, string $node, string $vmid, string $action): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
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
        $localVms = Vm::query()->with(['user', 'labTemplate'])->latest()->get();
        $visibleLocalVms = $this->localVmsForUser($user);
        $realVmResponse = $this->proxmox->listVms();
        $realVms = collect($realVmResponse['data'] ?? [])
            ->filter(fn ($vm) => is_array($vm))
            ->map(fn (array $vm) => $this->formatRealVm($vm, $user, $localVms))
            ->filter(fn (array $vm) => $vm['can_view'])
            ->values();

        return view('dashboard.index', [
            'section' => $section,
            'currentUser' => $user,
            'templates' => LabTemplate::query()->latest()->get(),
            'vms' => $visibleLocalVms,
            'realVmResponse' => $realVmResponse,
            'realVms' => $realVms,
            'auditLogs' => AuditLog::with(['user', 'vm'])->latest()->limit(50)->get(),
            'stats' => [
                'templates' => LabTemplate::count(),
                'vms' => $realVms->isNotEmpty() ? $realVms->count() : $visibleLocalVms->count(),
                'auditLogs' => AuditLog::count(),
                'users' => User::count(),
            ],
        ]);
    }

    private function localVmsForUser(?User $user): Collection
    {
        $query = Vm::withTrashed()
            ->with(['user', 'labTemplate'])
            ->latest();

        return match ($this->roleFor($user)) {
            'admin' => $query->get(),
            'guru' => $query->get(), // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            'student' => $user ? $query->where('user_id', $user->id)->get() : collect(),
            default => collect(),
        };
    }

    private function formatRealVm(array $vm, ?User $user, Collection $localVms): array
    {
        $vmid = (int) ($vm['vmid'] ?? 0);
        $node = (string) ($vm['node'] ?? '-');
        $localVm = $this->findLocalVmInCollection($localVms, $node, $vmid);
        $canAccess = $this->canAccessRealVm($user, $localVm);
        $systemVm = $this->metadataFlag($localVm, 'system_vm');
        $critical = $this->isCriticalVm($node, $vmid, $vm['name'] ?? null, $localVm);
        $protected = $systemVm || $critical;

        return [
            'vmid' => $vmid,
            'name' => $vm['name'] ?? '-',
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
        return $localVms->first(fn (Vm $vm) => $vm->matchesProxmoxVm($node, $vmid));
    }

    private function canAccessRealVm(?User $user, ?Vm $localVm): bool
    {
        $role = $this->roleFor($user);

        if ($role === 'admin') {
            return true;
        }

        if ($role === 'guru') {
            // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            return true;
        }

        if ($role === 'student') {
            return $user !== null && $localVm !== null && $localVm->user_id === $user->id;
        }

        return false;
    }

    private function roleFor(?User $user): string
    {
        return match ($user?->role) {
            'admin' => 'admin',
            'guru', 'teacher' => 'guru',
            'student', 'mahasiswa' => 'student',
            default => 'guest',
        };
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
        return $this->metadataFlag($localVm, 'system_vm')
            || $this->isCriticalVm($node, $vmid, $localVm?->name, $localVm);
    }

    private function isCriticalVm(string $node, int $vmid, ?string $name = null, ?Vm $localVm = null): bool
    {
        return $vmid === 101
            || $this->metadataFlag($localVm, 'critical')
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
        AuditLog::create([
            'user_id' => $userId,
            'vm_id' => $vmId,
            'action' => $action,
            'description' => $description,
            'metadata' => ['source' => 'web-dashboard', ...$metadata],
        ]);
    }
}
