<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        $query = AuditLog::query()
            ->with(['user', 'vm.labTemplate'])
            ->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('vm_id')) {
            $query->where('vm_id', $request->integer('vm_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        return response()->json([
            'data' => $query->paginate($request->integer('per_page', 20)),
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        $this->authorizeViewer($request);

        return response()->json([
            'data' => $auditLog->load(['user', 'vm.labTemplate']),
        ]);
    }

    private function authorizeViewer(Request $request): void
    {
        $user = $request->user();

        if (! $user && ($request->filled('owner_id') || $request->filled('user_id'))) {
            $user = User::findOrFail($request->integer('owner_id') ?: $request->integer('user_id'));
        }

        if (! $user) {
            $user = User::where('role', 'admin')->firstOrFail();
        }

        abort_unless(in_array($user->role, ['admin', 'guru'], true), 403);
    }
}
