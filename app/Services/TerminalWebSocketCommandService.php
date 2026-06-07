<?php

namespace App\Services;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class TerminalWebSocketCommandService
{
    private const MAX_COMMAND_LENGTH = 1000;
    private const OUTPUT_EXCERPT_LIMIT = 4000;

    public function __construct(
        private readonly SshCommandService $sshCommandService,
        private readonly VmSshHostRefreshService $sshHostRefresh,
    ) {
    }

    public function run(TerminalSession $terminalSession, User $user, string $command): TerminalWebSocketCommandResult
    {
        $originalCommand = $command;
        $command = trim($command);

        Log::debug('PAM websocket command received.', [
            'session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'user_id' => $user->id,
            'original_command' => $originalCommand,
            'normalized_command' => $command,
            'transport' => 'websocket',
        ]);

        if ($command === '') {
            return new TerminalWebSocketCommandResult('ignored', '', null, '');
        }

        $terminalSession = $terminalSession->fresh('vm') ?? $terminalSession;
        $terminalSession->loadMissing('vm');
        // Refresh status sebelum eksekusi agar revoke/expire dari dashboard langsung berlaku di WebSocket.
        $terminalSession->expireIfPastDue();
        $this->sshHostRefresh->refreshSession($terminalSession);

        if (mb_strlen($command) > self::MAX_COMMAND_LENGTH) {
            return $this->blocked($terminalSession, $user, $command, 'Command terlalu panjang untuk interactive terminal.');
        }

        // Policy command menjadi gerbang terakhir sebelum input user diteruskan ke SSH.
        $authorization = Gate::forUser($user)->inspect('execute', [CommandLog::class, $terminalSession, $command]);

        if ($authorization->denied()) {
            return $this->blocked(
                $terminalSession,
                $user,
                $command,
                $authorization->message() ?: CommandLog::blockedReasonFor($command) ?: 'Command diblokir oleh policy terminal.',
            );
        }

        if ($blockedReason = $this->terminalTargetBlockedReason($terminalSession)) {
            return $this->blocked($terminalSession, $user, $command, $blockedReason);
        }

        if ($terminalSession->isPending()) {
            // Sesi terminal dianggap aktif hanya setelah command pertama yang lolos policy.
            $terminalSession->forceFill([
                'status' => TerminalSessionStatus::Active,
                'metadata' => [
                    ...($terminalSession->metadata ?? []),
                    'ssh_ready' => true,
                    'transport' => 'websocket-command',
                ],
            ])->save();
        }
        $terminalSession->refresh();

        $commandLog = $this->createCommandLog($terminalSession, $user, $command);

        try {
            // WebSocket hanya transport; eksekusi tetap lewat SSH service agar audit flow seragam.
            $result = $this->sshCommandService->execute($terminalSession, $command);
            $outputExcerpt = $this->outputExcerpt($result->output ?: $result->error ?: 'SSH command execution failed.', $terminalSession);

            $result->successful
                ? $commandLog->markSucceeded($result->exitCode, $result->durationMs, $outputExcerpt)
                : $commandLog->markFailed($result->exitCode, $result->durationMs, $outputExcerpt);
            Log::debug('PAM websocket SSH result received.', [
                'session_id' => $terminalSession->id,
                'vm_id' => $terminalSession->vm_id,
                'user_id' => $user->id,
                'normalized_command' => $command,
                'status' => $result->successful ? 'succeeded' : 'failed',
                'exit_code' => $result->exitCode,
                'ssh_output' => $outputExcerpt,
                'ssh_error' => $result->error,
            ]);
        } catch (Throwable $exception) {
            $outputExcerpt = 'SSH command execution failed.';
            Log::debug('PAM websocket command exception.', [
                'session_id' => $terminalSession->id,
                'vm_id' => $terminalSession->vm_id,
                'user_id' => $user->id,
                'normalized_command' => $command,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
            $commandLog->markFailed(null, null, $outputExcerpt);
        }

        $terminalSession->touchActivity();

        return new TerminalWebSocketCommandResult(
            $commandLog->isSucceeded() ? 'output' : 'failed',
            $command,
            $commandLog->status->value,
            $outputExcerpt,
            $terminalSession->status->value,
        );
    }

    private function blocked(TerminalSession $terminalSession, User $user, string $command, string $reason): TerminalWebSocketCommandResult
    {
        // Percobaan command berbahaya tetap disimpan sebagai sinyal monitoring, bukan dibuang diam-diam.
        $commandLog = $this->createCommandLog($terminalSession, $user, $command);
        $commandLog->markBlocked($reason);

        if (! $terminalSession->isEnded() && ! $terminalSession->isExpired()) {
            $terminalSession->touchActivity();
        }

        return new TerminalWebSocketCommandResult(
            'blocked',
            $command,
            CommandLogStatus::Blocked->value,
            $reason,
        );
    }

    private function createCommandLog(TerminalSession $terminalSession, User $user, string $command): CommandLog
    {
        // Sumber websocket dibedakan supaya SOC dapat membedakan terminal interaktif dari form POC.
        return CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'vm_id' => $terminalSession->vm_id,
            'command' => $command,
            'status' => CommandLogStatus::Allowed,
            'executed_at' => now(),
            'metadata' => ['source' => 'terminal-websocket'],
        ]);
    }

    private function terminalTargetBlockedReason(TerminalSession $terminalSession): ?string
    {
        $terminalSession->loadMissing('vm');

        if (! $terminalSession->vm || $terminalSession->vm->trashed()) {
            return 'VM is no longer available for terminal access.';
        }

        if ($terminalSession->vm->status !== 'running') {
            return 'Start this VM before opening terminal access.';
        }

        if (! is_string($terminalSession->ssh_host) || trim($terminalSession->ssh_host) === '') {
            return 'SSH access for this VM is not configured yet.';
        }

        return null;
    }

    private function outputExcerpt(string $output, ?TerminalSession $terminalSession = null): string
    {
        $password = config('services.terminal.ssh_password');
        $excerpt = str($output)->replace("\0", '')->limit(self::OUTPUT_EXCERPT_LIMIT, "\n[output truncated]")->toString();

        foreach ([$password, $terminalSession?->vm?->sshPassword(), $terminalSession?->vm?->sshPrivateKey()] as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            // Redaksi secret menjaga output terminal aman saat ditampilkan di dashboard.
            $excerpt = str_replace($secret, '[redacted]', $excerpt);
        }

        return $excerpt;
    }
}
