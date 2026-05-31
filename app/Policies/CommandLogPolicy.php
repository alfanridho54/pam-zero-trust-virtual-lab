<?php

namespace App\Policies;

use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Log;

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
            : Response::deny($this->debugSessionAccessMessage($user, $terminalSession, __METHOD__));
    }

    public function execute(User $user, TerminalSession $terminalSession, string $command): Response
    {
        if (! $this->canAccessSession($user, $terminalSession)) {
            return Response::deny($this->debugSessionAccessMessage($user, $terminalSession, __METHOD__));
        }

        if ($this->protectedVm($terminalSession)) {
            // Pertahanan berlapis: command tetap diblokir walau ada session pada VM protected.
            return Response::deny('Command diblokir untuk VM system atau protected.');
        }

        $policy = $this->commandPolicy($terminalSession, $user);
        $blockedReason = CommandLog::blockedReasonFor($command, $policy);

        Log::debug('PAM command policy evaluated.', [
            'session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'user_id' => $user->id,
            'original_command' => $command,
            'normalized_command' => trim($command),
            'selected_policy' => $policy,
            'result' => $blockedReason ? 'blocked' : 'allowed',
            'blocked_reason' => $blockedReason,
            'shared_practical' => (bool) ($terminalSession->vm?->isSharedPractical() ?? false),
            'self_service_owned' => (bool) ($terminalSession->vm?->isSelfServiceOwnedBy($user) ?? false),
        ]);

        return $blockedReason
            ? Response::deny($blockedReason)
            : Response::allow();
    }

    public function blockedReason(string $command, ?TerminalSession $terminalSession = null, ?User $user = null): ?string
    {
        return CommandLog::blockedReasonFor($command, $this->commandPolicy($terminalSession, $user));
    }

    private function commandPolicy(?TerminalSession $terminalSession, ?User $user): string
    {
        $terminalSession?->loadMissing('vm');
        $vm = $terminalSession?->vm;

        if ($vm && $user && $vm->isSelfServiceOwnedBy($user)) {
            return 'relaxed';
        }

        return 'strict';
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

        $terminalSession->loadMissing('vm.practicalAccesses');
        $vm = $terminalSession->vm;

        if (! $vm || $vm->trashed()) {
            return false;
        }

        $canAccess = $terminalSession->canBeAccessedBy($user);

        if (! $canAccess) {
            $this->logDeniedSessionAccess($user, $terminalSession, 'command_log_policy');
        }

        return $canAccess;
    }

    private function protectedVm(TerminalSession $terminalSession): bool
    {
        return $terminalSession->vm?->isProtectedVm() ?? true;
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

    private function debugSessionAccessMessage(User $user, TerminalSession $terminalSession, string $method): string
    {
        $terminalSession->loadMissing('vm.practicalAccesses');
        $vm = $terminalSession->vm;
        $practicalAccessExists = $vm?->hasPracticalAccess($user) ?? false;
        $canBeAccessedBy = $terminalSession->canBeAccessedBy($user);

        return 'Anda tidak memiliki akses ke session terminal ini. Debug: '
            .'auth_id='.$user->id
            .'; session_id='.$terminalSession->id
            .'; session_user_id='.$terminalSession->user_id
            .'; vm_id='.$terminalSession->vm_id
            .'; canBeAccessedBy='.($canBeAccessedBy ? 'true' : 'false')
            .'; practical_access_exists='.($practicalAccessExists ? 'true' : 'false')
            .'; returned_by='.$method;
    }
}
