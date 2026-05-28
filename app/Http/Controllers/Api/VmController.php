<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VmController extends Controller
{
    public function __construct(private readonly ProxmoxService $proxmox) {}

    public function index(Request $request): JsonResponse
    {
        $query = Vm::query()->with(['user', 'labTemplate']);

        if ($request->filled('owner_id') || $request->filled('user_id') || $request->user()) {
            $user = $this->mockUser($request);

            if ($user->role === 'student') {
                // API VM menjaga isolasi ownership: siswa hanya menerima VM miliknya.
                $query->where('user_id', $user->id);
            }
        }

        return response()->json([
            'data' => $query->latest()->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_id' => ['required_without:user_id', 'exists:users,id'],
            'user_id' => ['required_without:owner_id', 'exists:users,id'],
            'lab_template_id' => ['nullable', 'exists:lab_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'cpu_cores' => ['sometimes', 'integer', 'min:1', 'max:32'],
            'memory_mb' => ['sometimes', 'integer', 'min:512', 'max:131072'],
            'disk_gb' => ['sometimes', 'integer', 'min:5', 'max:2048'],
            'node' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $user = $this->mockUser($request);
        unset($data['owner_id'], $data['user_id']);

        $quota = $user->quota;

        $quotaVms = $this->quotaVmsFor($user);

        if ($quota && $quotaVms->count() >= $quota->max_vms) {
            return response()->json(['message' => 'Kuota VM sudah penuh.'], 422);
        }

        if ($quota) {
            if (($data['cpu_cores'] ?? 1) > $quota->max_cpu_cores ||
                ($data['memory_mb'] ?? 1024) > $quota->max_memory_mb ||
                ($data['disk_gb'] ?? 10) > $quota->max_disk_gb) {
                return response()->json(['message' => 'Spesifikasi VM melebihi kuota.'], 422);
            }

            $requestedDiskGb = $data['disk_gb'] ?? 10;
            $usedDiskGb = (int) $quotaVms->sum('disk_gb');

            if ($usedDiskGb + $requestedDiskGb > $quota->max_disk_gb) {
                return response()->json(['message' => 'Kuota storage VM sudah penuh.'], 422);
            }
        }

        $proxmox = $this->proxmox->createVm($data);

        // Record lokal adalah sumber ownership; response Proxmox hanya menambahkan identitas infrastruktur.
        $vm = $user->vms()->create([
            ...$data,
            ...$proxmox,
        ]);

        $this->audit($user->id, $vm->id, 'vm.created', 'User membuat VM pribadi.', [
            'request' => $data,
            'proxmox' => $proxmox,
        ]);

        return response()->json(['data' => $vm->load('user', 'labTemplate')], 201);
    }

    public function show(Request $request, Vm $vm): JsonResponse
    {
        $user = $this->mockUser($request);
        // Semua operasi VM pribadi mengunci akses pada pemilik record lokal.
        abort_unless($vm->user_id === $user->id, 403);

        return response()->json(['data' => $vm->load('labTemplate')]);
    }

    public function update(Request $request, Vm $vm): JsonResponse
    {
        $user = $this->mockUser($request);
        abort_unless($vm->user_id === $user->id, 403);

        $data = $request->validate([
            'owner_id' => ['sometimes', 'exists:users,id'],
            'user_id' => ['sometimes', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'cpu_cores' => ['sometimes', 'integer', 'min:1', 'max:32'],
            'memory_mb' => ['sometimes', 'integer', 'min:512', 'max:131072'],
            'disk_gb' => ['sometimes', 'integer', 'min:5', 'max:2048'],
            'node' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:running,stopped,suspended'],
            'metadata' => ['sometimes', 'array'],
        ]);

        unset($data['owner_id'], $data['user_id']);

        $proxmox = $this->proxmox->updateVm($vm, $data);
        $vm->update([...$data, ...$proxmox]);

        $this->audit($user->id, $vm->id, 'vm.updated', 'User mengubah VM pribadi.', [
            'request' => $data,
            'proxmox' => $proxmox,
        ]);

        return response()->json(['data' => $vm->fresh()->load('labTemplate')]);
    }

    public function destroy(Request $request, Vm $vm): JsonResponse
    {
        $user = $this->mockUser($request);
        abort_unless($vm->user_id === $user->id, 403);

        $proxmox = $this->proxmox->deleteVm($vm);
        $vmId = $vm->id;

        $this->audit($user->id, $vmId, 'vm.deleted', 'User menghapus VM pribadi.', [
            'proxmox' => $proxmox,
        ]);

        $vm->delete();

        return response()->json(['message' => 'VM berhasil dihapus.']);
    }

    private function audit(int $userId, ?int $vmId, string $action, string $description, array $metadata = []): void
    {
        // Setiap perubahan VM dicatat untuk menghubungkan aksi user dengan state Proxmox/lokal.
        AuditLog::create([
            'user_id' => $userId,
            'vm_id' => $vmId,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    private function quotaVmsFor(User $user)
    {
        return $user->vms()
            ->studentVisible()
            ->get()
            ->filter(fn (Vm $vm) => $vm->isStudentVisible() && ! $vm->isManagedAssignment() && ! $vm->isSharedPractical());
    }

    private function mockUser(Request $request): User
    {
        if ($request->user()) {
            return $request->user();
        }

        return User::findOrFail($request->integer('owner_id') ?: $request->integer('user_id'));
    }
}
