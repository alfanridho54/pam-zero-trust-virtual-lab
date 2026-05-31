<?php

namespace App\Services;

use App\Models\TerminalSession;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use phpseclib3\Crypt\PublicKeyLoader;
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
        $terminalSession->loadMissing('vm');
        $credentials = $this->resolveCredentials($terminalSession);

        if ($credentials['password'] === null && $credentials['private_key'] === null) {
            throw new RuntimeException('SSH credentials are not configured.');
        }

        Log::debug('SSH credential source resolved.', [
            'terminal_session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'credential_source' => $credentials['source'],
        ]);

        $timeout = $timeoutSeconds ?? (int) config('services.terminal.command_timeout', 10);
        // Timeout dibatasi supaya command gantung tidak menahan worker terminal terlalu lama.
        $timeout = max(1, min($timeout, 30));
        $started = hrtime(true);

        try {
            $ssh = new SSH2(
                $terminalSession->ssh_host,
                $credentials['port'],
                $timeout,
            );
            $ssh->setTimeout($timeout);

            $credential = $credentials['private_key'] !== null
                ? PublicKeyLoader::loadPrivateKey($credentials['private_key'])
                : $credentials['password'];

            if (! $ssh->login($credentials['username'], $credential)) {
                $this->logCommandFailure($terminalSession, $credentials, 'SSH authentication failed.');

                return $this->failed($started, 'SSH authentication failed.');
            }

            // Pada tahap ini policy sudah diperiksa oleh caller; service ini hanya melakukan eksekusi SSH.
            Log::debug('SSH command dispatching.', [
                'host' => $terminalSession->ssh_host,
                'username' => $credentials['username'],
                'port' => $credentials['port'],
                'session_id' => $terminalSession->id,
                'vm_id' => $terminalSession->vm_id,
                'actual_command_sent_to_ssh' => $command,
                'timeout_seconds' => $timeout,
            ]);

            $output = $ssh->exec($command);
            $stderr = (string) $ssh->getStdError();

            if ($output === false) {
                $this->logCommandFailure(
                    $terminalSession,
                    $credentials,
                    'SSH command execution failed.',
                    command: $command,
                    stdout: '',
                    stderr: $stderr,
                    exitCode: $ssh->getExitStatus(),
                );

                return $this->failed($started, 'SSH command execution failed.');
            }

            $exitCode = $ssh->getExitStatus();
            $stdoutExcerpt = $this->safeOutputExcerpt($output, $terminalSession);
            $stderrExcerpt = $this->safeOutputExcerpt($stderr, $terminalSession);

            Log::debug('SSH command completed.', [
                'host' => $terminalSession->ssh_host,
                'username' => $credentials['username'],
                'port' => $credentials['port'],
                'session_id' => $terminalSession->id,
                'vm_id' => $terminalSession->vm_id,
                'original_command' => $command,
                'actual_command_sent_to_ssh' => $command,
                'exit_code' => $exitCode,
                'stdout' => $stdoutExcerpt,
                'stderr' => $stderrExcerpt,
            ]);

            if ($exitCode !== 0) {
                $reason = 'SSH command exited with a non-zero status.';
                $this->logCommandFailure(
                    $terminalSession,
                    $credentials,
                    $reason,
                    command: $command,
                    stdout: $output,
                    stderr: $stderr,
                    exitCode: $exitCode,
                );
            }

            return new SshCommandResult(
                successful: $exitCode === 0,
                exitCode: $exitCode,
                durationMs: $this->durationMs($started),
                output: $output,
            );
        } catch (Throwable $exception) {
            $this->logCommandFailure(
                $terminalSession,
                $credentials,
                'SSH command execution failed.',
                $exception,
                command: $command,
            );

            return $this->failed($started, 'SSH command execution failed.');
        }
    }

    public function safeConnectionSummary(TerminalSession $terminalSession): array
    {
        $terminalSession->loadMissing('vm');
        $credentials = $this->resolveCredentials($terminalSession);

        return [
            'host' => $terminalSession->ssh_host,
            'port' => $credentials['port'],
            'username' => $credentials['username'],
            'credential_source' => $credentials['source'],
        ];
    }

    public function resolveCredentials(TerminalSession $terminalSession): array
    {
        $vm = $terminalSession->vm;
        $dynamicVm = (bool) $vm?->isProvisionedStudentVm();
        $username = $dynamicVm
            ? ($vm?->getResolvedSshUsername() ?: ($terminalSession->ssh_username ?: 'student'))
            : ($terminalSession->ssh_username ?: 'student');
        $port = $dynamicVm
            ? (int) ($vm?->sshPort() ?: ($terminalSession->ssh_port ?: 22))
            : (int) ($terminalSession->ssh_port ?: 22);
        $privateKey = $vm?->sshPrivateKey();
        $password = null;
        $source = 'none';

        if ($privateKey !== null) {
            $source = $dynamicVm ? 'vm_metadata_private_key' : 'vm_private_key';
        } elseif ($dynamicVm) {
            $password = $vm?->getResolvedSshPassword();
            $source = $password !== null ? 'vm_metadata_password' : 'none';
        } else {
            $password = $vm?->getResolvedSshPassword();

            if ($password !== null) {
                $source = 'vm_metadata_password';
            } else {
                $password = $this->stringConfig('services.terminal.ssh_password');
                $source = $password !== null ? 'config_fallback' : 'none';
            }
        }

        return [
            'username' => $username,
            'password' => $password,
            'private_key' => $privateKey,
            'port' => $port,
            'source' => $source,
        ];
    }

    private function stringMetadata(array $metadata, string $key, ?string $default = null): ?string
    {
        $value = $metadata[$key] ?? $default;

        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    private function integerMetadata(array $metadata, string $key, int $default): int
    {
        $value = $metadata[$key] ?? null;

        if (! is_numeric($value)) {
            return $default;
        }

        $value = (int) $value;

        return $value >= 1 && $value <= 65535 ? $value : $default;
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function logCommandFailure(
        TerminalSession $terminalSession,
        array $credentials,
        string $message,
        ?Throwable $exception = null,
        ?string $command = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?int $exitCode = null,
    ): void {
        $stdoutExcerpt = $stdout === null ? null : $this->safeOutputExcerpt($stdout, $terminalSession);
        $stderrExcerpt = $stderr === null ? null : $this->safeOutputExcerpt($stderr, $terminalSession);

        Log::warning('SSH command failed.', [
            'host' => $terminalSession->ssh_host,
            'username' => $credentials['username'] ?? $terminalSession->ssh_username,
            'port' => $credentials['port'] ?? $terminalSession->ssh_port,
            'session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'original_command' => $command,
            'actual_command_sent_to_ssh' => $command,
            'stdout' => $stdoutExcerpt,
            'stderr' => $stderrExcerpt,
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $exception?->getMessage() ?: $message,
            'exit_code' => $exitCode,
            'failure_reason' => $this->failureReason($message, $exitCode, $stdoutExcerpt, $stderrExcerpt),
        ]);
    }

    private function safeOutputExcerpt(string $output, TerminalSession $terminalSession): string
    {
        $excerpt = str($output)->replace("\0", '')->limit(1000, "\n[output truncated]")->toString();

        foreach ([
            config('services.terminal.ssh_password'),
            $terminalSession->vm?->sshPassword(),
            $terminalSession->vm?->sshPrivateKey(),
        ] as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $excerpt = str_replace($secret, '[redacted]', $excerpt);
        }

        return $excerpt;
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

    private function failureReason(string $message, ?int $exitCode, ?string $stdout, ?string $stderr): string
    {
        if ($exitCode !== null) {
            $streams = [];

            if (is_string($stdout) && $stdout !== '') {
                $streams[] = 'stdout present';
            }

            if (is_string($stderr) && $stderr !== '') {
                $streams[] = 'stderr present';
            }

            return trim($message.' exit_code='.$exitCode.($streams ? ' ('.implode(', ', $streams).')' : ''));
        }

        return $message;
    }
}
