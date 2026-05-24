<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\LabAccessController;
use App\Http\Controllers\Api\VmController;
use Illuminate\Support\Facades\Route;

Route::apiResource('vms', VmController::class);

Route::get('lab-templates', [LabAccessController::class, 'templates']);
Route::get('lab-access', [LabAccessController::class, 'myLabs']);
Route::post('lab-access/{labTemplate}', [LabAccessController::class, 'access']);

Route::get('audit-logs', [AuditLogController::class, 'index']);
Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
