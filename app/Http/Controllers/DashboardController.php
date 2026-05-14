<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LabTemplate;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private function view(string $section): View
    {
        return view('dashboard.index', [
            'section' => $section,
            'templates' => LabTemplate::query()->latest()->get(),
            'vms' => Vm::withTrashed()->with(['user', 'labTemplate'])->latest()->get(),
            'auditLogs' => AuditLog::with(['user', 'vm'])->latest()->limit(50)->get(),
            'stats' => [
                'templates' => LabTemplate::count(),
                'vms' => Vm::withTrashed()->count(),
                'auditLogs' => AuditLog::count(),
                'users' => User::count(),
            ],
        ]);
    }

    private function audit(int $userId, ?int $vmId, string $action, string $description): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'vm_id' => $vmId,
            'action' => $action,
            'description' => $description,
            'metadata' => ['source' => 'web-dashboard'],
        ]);
    }
}
