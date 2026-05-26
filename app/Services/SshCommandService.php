<?php

namespace App\Services;

use App\Models\TerminalSession;
use RuntimeException;
use phpseclib3\Net\SSH2;
use Throwable;

class SshCommandService
{
    /**
     * Menjalankan command melalui SSH target yang terikat pada TerminalSession.
     * Service ini sengaja kecil agar semua transport memakai jalur eksekusi dan timeout yang sama.
     */
    public function execute(TerminalSession $terminalSession, string $command, ?int $timeoutSeconds = null): SshCommandResult
    {
        $password = config('services.terminal.ssh_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SSH password is not configured.');
        }

        $timeout = $timeoutSeconds ?? (int) config('services.terminal.command_timeout', 10);
        // Timeout dibatasi supaya command gantung tidak menahan worker terminal terlalu lama.
        $timeout = max(1, min($timeout, 30));
        $started = hrtime(true);

        try {
            $ssh = new SSH2(
                $terminalSession->ssh_host,
                $terminalSession->ssh_port,
                $timeout,
            );
            $ssh->setTimeout($timeout);

            if (! $ssh->login($terminalSession->ssh_username, $password)) {
                return $this->failed($started, 'SSH authentication failed.');
            }

            // Pada tahap ini policy sudah diperiksa oleh caller; service ini hanya melakukan eksekusi SSH.
            $output = $ssh->exec($command);

            if ($output === false) {
                return $this->failed($started, 'SSH command execution failed.');
            }

            $exitCode = $ssh->getExitStatus();

            return new SshCommandResult(
                successful: $exitCode === 0,
                exitCode: $exitCode,
                durationMs: $this->durationMs($started),
                output: $output,
            );
        } catch (Throwable) {
            return $this->failed($started, 'SSH command execution failed.');
        }
    }

    private function failed(int $started, string $message): SshCommandResult
    {
        return new SshCommandResult(
            successful: false,
            exitCode: null,
            durationMs: $this->durationMs($started),
            output: '',
            error: $message,
        );
    }

    private function durationMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
