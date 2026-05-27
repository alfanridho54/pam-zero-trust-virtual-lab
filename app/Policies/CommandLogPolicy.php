<?php

namespace App\Policies;

use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommandLogPolicy
{
    public function view(User $user, CommandLog $commandLog): Response
    {
        return $this->canAccessSession($user, $commandLog->terminalSession)
            ? Response::allow()
            : Response::deny('Anda tidak memiliki akses ke log command ini.');
    }

    public function create(User $user, TerminalSession $terminalSession): Response
    {
        return $this->canAccessSession($user, $terminalSession)
            ? Response::allow()
            : Response::deny('Anda tidak memiliki akses ke session terminal ini.');
    }

    public function execute(User $user, TerminalSession $terminalSession, string $command): Response
    {
        if (! $this->canAccessSession($user, $terminalSession)) {
            return Response::deny('Anda tidak memiliki akses ke session terminal ini.');
        }

        if ($this->protectedVm($terminalSession)) {
            // Pertahanan berlapis: command tetap diblokir walau ada session pada VM protected.
            return Response::deny('Command diblokir untuk VM system atau protected.');
        }

        $blockedReason = $this->blockedReason($command);

        return $blockedReason
            ? Response::deny($blockedReason)
            : Response::allow();
    }

    public function blockedReason(string $command): ?string
    {
        return CommandLog::blockedReasonFor($command);
    }

    private function canAccessSession(User $user, TerminalSession $terminalSession): bool
    {
        if ($terminalSession->isEnded() || $terminalSession->isExpired()) {
            // Session final tidak boleh dipakai lagi, termasuk oleh request/WebSocket lama.
            return false;
        }

        if (! $terminalSession->isActive() && ! $terminalSession->isPending()) {
            return false;
        }

        $vm = $terminalSession->vm;

        if (! $vm || $vm->trashed()) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'guru') {
            // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            return true;
        }

        // Siswa harus menjadi pemilik session sekaligus pemilik VM target.
        return in_array($user->role, ['student', 'mahasiswa'], true)
            && $terminalSession->user_id === $user->id
            && $vm->user_id === $user->id;
    }

    private function protectedVm(TerminalSession $terminalSession): bool
    {
        return $terminalSession->vm?->isProtectedVm() ?? true;
    }
}
