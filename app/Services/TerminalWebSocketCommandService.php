<?php

namespace App\Services;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Throwable;

class TerminalWebSocketCommandService
{
    private const MAX_COMMAND_LENGTH = 1000;
    private const OUTPUT_EXCERPT_LIMIT = 4000;

    public function __construct(
        private readonly SshCommandService $sshCommandService,
    ) {
    }

    public function run(TerminalSession $terminalSession, User $user, string $command): TerminalWebSocketCommandResult
    {
        $command = trim($command);

        if ($command === '') {
            return new TerminalWebSocketCommandResult('ignored', '', null, '');
        }

        $terminalSession->refresh();
        $terminalSession->load('vm');
        $terminalSession->expireIfPastDue();

        if (mb_strlen($command) > self::MAX_COMMAND_LENGTH) {
            return $this->blocked($terminalSession, $user, $command, 'Command terlalu panjang untuk interactive terminal.');
        }

        $authorization = Gate::forUser($user)->inspect('execute', [CommandLog::class, $terminalSession, $command]);

        if ($authorization->denied()) {
            return $this->blocked(
                $terminalSession,
                $user,
                $command,
                $authorization->message() ?: CommandLog::blockedReasonFor($command) ?: 'Command diblokir oleh policy terminal.',
            );
        }

        if ($terminalSession->isPending()) {
            $terminalSession->forceFill([
                'status' => TerminalSessionStatus::Active,
                'metadata' => [
                    ...($terminalSession->metadata ?? []),
                    'ssh_ready' => true,
                    'transport' => 'websocket-command',
                ],
            ])->save();
        }

        $commandLog = $this->createCommandLog($terminalSession, $user, $command);

        try {
            $result = $this->sshCommandService->execute($terminalSession, $command);
            $outputExcerpt = $this->outputExcerpt($result->output ?: $result->error ?: 'SSH command execution failed.');

            $result->successful
                ? $commandLog->markSucceeded($result->exitCode, $result->durationMs, $outputExcerpt)
                : $commandLog->markFailed($result->exitCode, $result->durationMs, $outputExcerpt);
        } catch (Throwable) {
            $outputExcerpt = 'SSH command execution failed.';
            $commandLog->markFailed(null, null, $outputExcerpt);
        }

        $terminalSession->touchActivity();

        return new TerminalWebSocketCommandResult(
            $commandLog->isSucceeded() ? 'output' : 'failed',
            $command,
            $commandLog->status->value,
            $outputExcerpt,
        );
    }

    private function blocked(TerminalSession $terminalSession, User $user, string $command, string $reason): TerminalWebSocketCommandResult
    {
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

    private function outputExcerpt(string $output): string
    {
        $password = config('services.terminal.ssh_password');
        $excerpt = str($output)->replace("\0", '')->limit(self::OUTPUT_EXCERPT_LIMIT, "\n[output truncated]")->toString();

        if (is_string($password) && $password !== '') {
            $excerpt = str_replace($password, '[redacted]', $excerpt);
        }

        return $excerpt;
    }
}
