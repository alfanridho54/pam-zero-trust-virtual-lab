<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LabTemplate;
use App\Models\User;
use App\Services\ProxmoxService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LabAccessController extends Controller
{
    public function __construct(private readonly ProxmoxService $proxmox)
    {
    }

    public function templates(Request $request): JsonResponse
    {
        return response()->json([
            'data' => LabTemplate::query()
                ->where('is_active', true)
                ->latest()
                ->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function myLabs(Request $request): JsonResponse
    {
        $user = $this->mockUser($request);

        return response()->json([
            'data' => $user
                ->vms()
                ->whereNotNull('lab_template_id')
                ->with('labTemplate')
                ->latest()
                ->paginate($request->integer('per_page', 15)),
        ]);
    }

    public function access(Request $request, LabTemplate $labTemplate): JsonResponse
    {
        abort_unless($labTemplate->is_active, 404);

        $user = $this->mockUser($request);
        $existingVm = $user->vms()
            ->where('lab_template_id', $labTemplate->id)
            ->first();

        if ($existingVm) {
            return response()->json(['data' => $existingVm->load('labTemplate')]);
        }

        $vmName = $request->string('name')->toString() ?: $labTemplate->name.' - '.$user->name;
        $proxmox = $this->proxmox->cloneFromTemplate($labTemplate, $vmName);

        $vm = $user->vms()->create([
            'lab_template_id' => $labTemplate->id,
            'name' => $proxmox['name'],
            'proxmox_id' => $proxmox['proxmox_id'],
            'node' => $proxmox['node'],
            'status' => $proxmox['status'],
            'cpu_cores' => $labTemplate->cpu_cores,
            'memory_mb' => $labTemplate->memory_mb,
            'disk_gb' => $labTemplate->disk_gb,
            'metadata' => [
                'source_template_id' => $proxmox['source_template_id'],
            ],
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'action' => 'lab.accessed',
            'description' => 'User mengakses VM praktikum.',
            'metadata' => [
                'lab_template_id' => $labTemplate->id,
                'proxmox' => $proxmox,
            ],
        ]);

        return response()->json(['data' => $vm->load('labTemplate')], 201);
    }

    private function mockUser(Request $request): User
    {
        if ($request->user()) {
            return $request->user();
        }

        return User::findOrFail($request->integer('owner_id') ?: $request->integer('user_id'));
    }
}
