<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StudentVmController;
use App\Http\Controllers\TerminalSessionController;
use App\Http\Controllers\VmTemplateController;
use App\Services\ProxmoxService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/templates', [DashboardController::class, 'templates'])->name('dashboard.templates');
Route::get('/dashboard/vms', [DashboardController::class, 'vms'])->name('dashboard.vms');
Route::get('/dashboard/audit-logs', [DashboardController::class, 'auditLogs'])->name('dashboard.audit-logs');
Route::get('/dashboard/soc', [DashboardController::class, 'socMonitoring'])->name('dashboard.soc');
Route::post('/dashboard/vm-templates', [VmTemplateController::class, 'store'])->name('dashboard.vm-templates.store');
Route::put('/dashboard/vm-templates/{vmTemplate}', [VmTemplateController::class, 'update'])->name('dashboard.vm-templates.update');
Route::delete('/dashboard/vm-templates/{vmTemplate}', [VmTemplateController::class, 'destroy'])->name('dashboard.vm-templates.destroy');

Route::post('/dashboard/simulate/docker-lab', [DashboardController::class, 'createDockerLab'])->name('dashboard.simulate.docker-lab');
Route::post('/dashboard/simulate/vms/{vm}/resources', [DashboardController::class, 'editVmResource'])->name('dashboard.simulate.vm.resources');
Route::post('/dashboard/vms/bulk-managed-generation', [DashboardController::class, 'bulkGenerateManagedVms'])->name('dashboard.vms.bulk-managed-generation.store');
Route::post('/dashboard/vms/{vm}/shared-practical', [DashboardController::class, 'markSharedPractical'])->withTrashed()->name('dashboard.vms.shared-practical.store');
Route::delete('/dashboard/vms/{vm}/shared-practical', [DashboardController::class, 'unmarkSharedPractical'])->withTrashed()->name('dashboard.vms.shared-practical.destroy');
Route::post('/dashboard/vms/{vm}/practical-accesses', [DashboardController::class, 'grantPracticalAccess'])->withTrashed()->name('dashboard.vms.practical-accesses.store');
Route::delete('/dashboard/vms/{vm}/practical-accesses', [DashboardController::class, 'revokePracticalAccess'])->withTrashed()->name('dashboard.vms.practical-accesses.destroy');
Route::post('/dashboard/vms/{vm}/assignment', [DashboardController::class, 'assignVm'])->withTrashed()->name('dashboard.vms.assignment.store');
Route::delete('/dashboard/vms/{vm}/assignment', [DashboardController::class, 'unassignVm'])->withTrashed()->name('dashboard.vms.assignment.destroy');
Route::post('/dashboard/vms/{vm}/ssh-metadata', [DashboardController::class, 'updateVmSshMetadata'])->name('dashboard.vms.ssh-metadata.update');
Route::post('/dashboard/vms/{vm}/ssh-metadata/refresh', [DashboardController::class, 'refreshVmSshMetadata'])->name('dashboard.vms.ssh-metadata.refresh');
Route::delete('/dashboard/simulate/vms/{vm}', [DashboardController::class, 'deleteVm'])->name('dashboard.simulate.vm.delete');
Route::post('/dashboard/proxmox/vms/{node}/{vmid}/{action}', [DashboardController::class, 'proxmoxVmAction'])
    ->whereNumber('vmid')
    ->where('node', '[A-Za-z0-9._-]+')
    ->where('action', 'start|stop|shutdown')
    ->name('dashboard.proxmox.vms.action');

Route::get('/student/vms', [StudentVmController::class, 'index'])->name('student.vms.index');
Route::post('/student/vms', [StudentVmController::class, 'store'])->name('student.vms.store');
Route::post('/student/vms/{vm}/{action}', [StudentVmController::class, 'action'])
    ->where('action', 'start|stop|shutdown')
    ->name('student.vms.action');
Route::delete('/student/vms/{vm}', [StudentVmController::class, 'destroy'])->name('student.vms.destroy');

Route::post('/vms/{vm}/terminal-sessions', [TerminalSessionController::class, 'store'])
    ->name('terminal-sessions.store');
Route::get('/terminal-sessions/{terminalSession}', [TerminalSessionController::class, 'show'])
    ->name('terminal-sessions.show');
Route::post('/terminal-sessions/{terminalSession}/commands', [TerminalSessionController::class, 'executeCommand'])
    ->name('terminal-sessions.commands.store');
Route::post('/terminal-sessions/{terminalSession}/revoke', [TerminalSessionController::class, 'revoke'])
    ->name('terminal-sessions.revoke');
Route::delete('/terminal-sessions/{terminalSession}', [TerminalSessionController::class, 'destroy'])
    ->name('terminal-sessions.destroy');

Route::get('/test-proxmox', function (ProxmoxService $proxmox) {
    return response()->json($proxmox->listVms());
})->name('proxmox.test.vms');
