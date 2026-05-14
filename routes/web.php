<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/templates', [DashboardController::class, 'templates'])->name('dashboard.templates');
Route::get('/dashboard/vms', [DashboardController::class, 'vms'])->name('dashboard.vms');
Route::get('/dashboard/audit-logs', [DashboardController::class, 'auditLogs'])->name('dashboard.audit-logs');

Route::post('/dashboard/simulate/docker-lab', [DashboardController::class, 'createDockerLab'])->name('dashboard.simulate.docker-lab');
Route::post('/dashboard/simulate/vms/{vm}/resources', [DashboardController::class, 'editVmResource'])->name('dashboard.simulate.vm.resources');
Route::delete('/dashboard/simulate/vms/{vm}', [DashboardController::class, 'deleteVm'])->name('dashboard.simulate.vm.delete');
