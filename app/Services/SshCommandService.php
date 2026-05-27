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

    protected function resolveCredentials(TerminalSession $terminalSession): array
    {
        $vm = $terminalSession->vm;
        $metadata = is_array($vm?->metadata) ? $vm->metadata : [];
        $dynamicVm = (bool) $vm?->isProvisionedStudentVm();
        $username = $dynamicVm
            ? $this->stringMetadata($metadata, 'ssh_username', $terminalSession->ssh_username ?: 'student')
            : ($terminalSession->ssh_username ?: 'student');
        $port = $dynamicVm
            ? $this->integerMetadata($metadata, 'ssh_port', (int) ($terminalSession->ssh_port ?: 22))
            : (int) ($terminalSession->ssh_port ?: 22);
        $privateKey = $dynamicVm ? $this->stringMetadata($metadata, 'ssh_private_key') : $vm?->sshPrivateKey();
        $password = null;
        $source = 'none';

        if ($privateKey !== null) {
            $source = $dynamicVm ? 'vm_metadata_private_key' : 'vm_private_key';
        } elseif ($dynamicVm) {
            $password = $this->stringMetadata($metadata, 'ssh_password');
            $source = $password !== null ? 'vm_metadata_password' : 'none';
        } else {
            $password = $vm?->sshPassword();

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
