<?php

namespace App\Services;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class TerminalPtySessionService
{
    public function __construct(private readonly SshCommandService $sshCommandService)
    {
    }

    public function canOpen(TerminalSession $terminalSession, User $user): bool
    {
        $terminalSession->loadMissing('vm');
        $terminalSession->expireIfPastDue();

        $vm = $terminalSession->vm;

        return ! $terminalSession->isEnded()
            && ! $terminalSession->isExpired()
            && $vm !== null
            && ! $vm->trashed()
            && $vm->status === 'running'
            && $terminalSession->canBeAccessedBy($user)
            && ($vm->isSelfServiceOwnedBy($user) || $this->canOpenTemporarySharedPracticalSession($terminalSession, $user))
            && function_exists('proc_open')
            && $this->commandExists('ssh')
            && $this->hasSupportedAuthentication($terminalSession);
    }

    public function open(TerminalSession $terminalSession, User $user): TerminalPtyProcess
    {
        if (! $this->canOpen($terminalSession, $user)) {
            throw new \RuntimeException('PTY mode is not available for this terminal session.');
        }

        $credentials = $this->connectionCredentials($terminalSession, $user);
        $host = $terminalSession->ssh_host;
        $username = $credentials['username'] ?? $terminalSession->ssh_username ?? 'student';
        $port = (int) ($credentials['port'] ?? $terminalSession->ssh_port ?? 22);
        $temporaryPrivateKeyPath = null;
        $environment = null;

        if (($credentials['password'] ?? null) === null && ($credentials['private_key'] ?? null) === null) {
            throw new \RuntimeException('SSH credentials are not configured.');
        }

        $command = [
            'ssh',
            '-tt',
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'LogLevel=ERROR',
            '-p', (string) $port,
        ];

        if (($credentials['private_key'] ?? null) !== null) {
            $temporaryPrivateKeyPath = $this->writeTemporaryPrivateKey($credentials['private_key']);
            $command[] = '-i';
            $command[] = $temporaryPrivateKeyPath;
        } elseif (($credentials['password'] ?? null) !== null) {
            if (! $this->commandExists('sshpass')) {
                throw new \RuntimeException('sshpass is not installed, so password-based PTY cannot be opened.');
            }

            $environment = getenv();
            $environment = is_array($environment) ? $environment : [];
            $environment['SSHPASS'] = $credentials['password'];
            $command = [
                'sshpass',
                '-e',
                ...$command,
            ];
        }

        $command[] = $username.'@'.$host;

        Log::debug('PTY terminal opening SSH connection.', [
            'terminal_session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'user_id' => $user->id,
            'host' => $host,
            'username' => $username,
            'port' => $port,
            'pty_method' => 'proc_open_ssh_tt',
            'auth_method' => ($credentials['private_key'] ?? null) !== null ? 'private_key' : 'sshpass_password',
        ]);

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment);

        if (! is_resource($process)) {
            if ($temporaryPrivateKeyPath !== null && is_file($temporaryPrivateKeyPath)) {
                @unlink($temporaryPrivateKeyPath);
            }

            throw new \RuntimeException('Unable to start SSH PTY subprocess.');
        }

        $ptyProcess = new TerminalPtyProcess($process, $pipes, $temporaryPrivateKeyPath);

        if ($terminalSession->isPending()) {
            $terminalSession->forceFill([
                'status' => TerminalSessionStatus::Active,
                'metadata' => [
                    ...($terminalSession->metadata ?? []),
                    'transport' => 'ssh-pty',
                    'pty_mode' => true,
                    'pty_method' => 'proc_open_ssh_tt',
                    'temporary_username' => $terminalSession->temporaryUsername(),
                ],
            ])->save();
        }

        Log::info('PTY terminal session opened.', [
            'terminal_session_id' => $terminalSession->id,
            'vm_id' => $terminalSession->vm_id,
            'user_id' => $user->id,
            'host' => $host,
            'username' => $username,
            'port' => $port,
            'pty_method' => 'proc_open_ssh_tt',
            'auth_method' => ($credentials['private_key'] ?? null) !== null ? 'private_key' : 'sshpass_password',
        ]);

        return $ptyProcess;
    }

    public function readAvailable(TerminalPtyProcess $ptyProcess): string
    {
        return $ptyProcess->readAvailable();
    }

    public function write(TerminalSession $terminalSession, User $user, TerminalPtyProcess $ptyProcess, string $input, bool $logCommand = false): void
    {
        if (! $this->canOpen($terminalSession, $user)) {
            throw new \RuntimeException('Terminal session is no longer active.');
        }

        if (! $ptyProcess->isRunning()) {
            throw new \RuntimeException('SSH PTY subprocess is not running.');
        }

        $ptyProcess->write($input);
        $terminalSession->touchActivity();

        if ($logCommand) {
            $command = trim($input);

            if ($command !== '') {
                CommandLog::create([
                    'terminal_session_id' => $terminalSession->id,
                    'user_id' => $user->id,
                    'vm_id' => $terminalSession->vm_id,
                    'command' => $command,
                    'status' => CommandLogStatus::Allowed,
                    'executed_at' => now(),
                    'metadata' => [
                        'source' => 'terminal-pty',
                        'temporary_username' => $terminalSession->temporaryUsername(),
                    ],
                ]);
            }
        }
    }

    private function hasSupportedAuthentication(TerminalSession $terminalSession): bool
    {
        $credentials = $this->connectionCredentials($terminalSession);

        if (($credentials['private_key'] ?? null) !== null) {
            return true;
        }

        return ($credentials['password'] ?? null) !== null && $this->commandExists('sshpass');
    }

    private function canOpenTemporarySharedPracticalSession(TerminalSession $terminalSession, User $user): bool
    {
        $terminalSession->loadMissing('vm');
        $vm = $terminalSession->vm;

        return $vm !== null
            && $vm->isSharedPractical()
            && $terminalSession->canBeAccessedBy($user)
            && $terminalSession->temporaryCredentialIsActive();
    }

    private function connectionCredentials(TerminalSession $terminalSession, ?User $user = null): array
    {
        $terminalSession->loadMissing('vm');

        if (
            $terminalSession->vm?->isSharedPractical()
            && $terminalSession->temporaryCredentialIsActive()
        ) {
            return [
                'username' => $terminalSession->temporaryUsername(),
                'password' => Crypt::decryptString($terminalSession->encryptedTemporaryPassword()),
                'private_key' => null,
                'port' => (int) ($terminalSession->ssh_port ?: 22),
                'source' => 'terminal_session_temporary_password',
            ];
        }

        return $this->sshCommandService->resolveCredentials($terminalSession);
    }

    private function commandExists(string $command): bool
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));

        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$command;

            if (is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function writeTemporaryPrivateKey(string $privateKey): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pam-pty-key-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary SSH private key file.');
        }

        file_put_contents($path, $privateKey);
        chmod($path, 0600);

        return $path;
    }
}
