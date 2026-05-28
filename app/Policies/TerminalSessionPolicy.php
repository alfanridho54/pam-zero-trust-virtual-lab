<?php

namespace App\Policies;

use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Log;

class TerminalSessionPolicy
{
    /**
     * Akses terminal mengikuti akses VM agar session tidak menjadi jalur bypass ownership.
     */
    public function view(User $user, TerminalSession $terminalSession): Response
    {
        $terminalSession->loadMissing('vm.practicalAccesses');

        $canAccess = $terminalSession->canBeAccessedBy($user);

        if (! $canAccess) {
            $this->logDeniedSessionAccess($user, $terminalSession, 'terminal_session_policy');
        }

        return $canAccess
            ? Response::allow()
            : Response::deny('Anda tidak memiliki akses ke VM ini.');
    }

    public function create(User $user, Vm $vm): Response
    {
        if ($vm->isProtectedVm()) {
            // VM system/critical tidak boleh dibuka terminalnya dari lab student.
            return Response::deny('Terminal tidak tersedia untuk VM system atau critical.');
        }

        return $this->canAccessVm($user, $vm)
            ? Response::allow()
            : Response::deny('Anda tidak memiliki akses ke VM ini.');
    }

    public function close(User $user, TerminalSession $terminalSession): Response
    {
        return $this->view($user, $terminalSession);
    }

    public function revoke(User $user, TerminalSession $terminalSession): Response
    {
        if ($user->role !== 'admin') {
            // Revoke adalah kontrol darurat SOC/admin, bukan aksi pemilik sesi.
            return Response::deny('Hanya admin yang dapat revoke terminal session.');
        }

        return $terminalSession->isActive()
            ? Response::allow()
            : Response::deny('Terminal session tidak aktif.');
    }

    private function canAccessVm(User $user, ?Vm $vm): bool
    {
        if (! $vm || $vm->trashed()) {
            return false;
        }

        // RBAC sederhana: admin/guru dapat supervisi, student dibatasi pada VM miliknya.
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'guru') {
            // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            return true;
        }

        return in_array($user->role, ['student', 'mahasiswa', 'siswa'], true)
            && ($vm->user_id === $user->id || $vm->hasPracticalAccess($user));
    }

    private function logDeniedSessionAccess(User $user, TerminalSession $terminalSession, string $source): void
    {
        $vm = $terminalSession->vm;
        $practicalAccessExists = $vm?->hasPracticalAccess($user) ?? false;

        Log::warning('Terminal session access denied.', [
            'source' => $source,
            'auth_id' => $user->id,
            'session_id' => $terminalSession->id,
            'session_user_id' => $terminalSession->user_id,
            'session_vm_id' => $terminalSession->vm_id,
            'vm_user_id' => $vm?->user_id,
            'shared_practical' => (bool) ($vm?->metadata['shared_practical'] ?? false),
            'practical_access_exists' => $practicalAccessExists,
            'can_be_accessed_by' => false,
        ]);
    }
}
