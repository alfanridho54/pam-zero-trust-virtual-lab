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
        if (! $terminalSession->isActive()) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'guru') {
            // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            return true;
        }

        return in_array($user->role, ['student', 'mahasiswa'], true)
            && $terminalSession->user_id === $user->id
            && $terminalSession->vm?->user_id === $user->id;
    }
}
