<?php

namespace App\Policies;

use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Auth\Access\Response;

class TerminalSessionPolicy
{
    /**
     * Akses terminal mengikuti akses VM agar session tidak menjadi jalur bypass ownership.
     */
    public function view(User $user, TerminalSession $terminalSession): Response
    {
        return $this->canAccessVm($user, $terminalSession->vm)
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

        return in_array($user->role, ['student', 'mahasiswa'], true)
            && $vm->user_id === $user->id;
    }
}
