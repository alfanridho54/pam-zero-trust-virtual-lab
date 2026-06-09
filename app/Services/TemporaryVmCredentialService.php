<?php

namespace App\Services;

use App\Models\TerminalSession;
use App\Models\Vm;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

class TemporaryVmCredentialService
{
    private const STATUS_CREATING = 'creating';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_FAILED = 'failed';
    private const STATUS_CLEANUP_RUNNING = 'cleanup_running';
    private const STATUS_CLEANUP_FAILED = 'cleanup_failed';
    private const STATUS_CLEANED = 'cleaned';
    private const STATUS_ALREADY_REMOVED = 'already_removed';

    public function __construct(private readonly SshCommandService $sshCommandService)
    {
    }

    /**
     * @return array{username: string, password: string}
     */
    public function createTemporaryUser(Vm $vm, TerminalSession $session): array
    {
        $session->loadMissing('vm');

        if (! $vm->isSharedPractical()) {
            throw new RuntimeException('Temporary VM credentials are only available for Shared Practical VM sessions.');
        }

        if ($session->temporaryCredentialIsActive()) {
            $password = Crypt::decryptString($session->encryptedTemporaryPassword());

            return [
                'username' => $session->temporaryUsername(),
                'password' => $password,
            ];
        }

        $username = $this->generateUsername($session);
        $password = $this->generatePassword();

        $session->mergeTemporaryCredentialMetadata([
            'enabled' => true,
            'username' => $username,
            'status' => self::STATUS_CREATING,
            'created_at' => now()->toISOString(),
            'last_error' => null,
        ]);

        try {
            $ssh = $this->connect($session);

            $this->ensureUsernameAvailable($ssh, $username);
            $this->runRequired($ssh, 'useradd', 'sudo -n useradd -m -s /bin/bash '.escapeshellarg($username));
            $this->runRequired(
                $ssh,
                'chpasswd',
                'printf %s '.escapeshellarg($username.':'.$password."\n").' | sudo -n chpasswd',
                containsSecret: true,
            );
            $this->runRequired($ssh, 'id', 'sudo -n id '.escapeshellarg($username));

            $session->refresh();
            $session->mergeTemporaryCredentialMetadata([
                'enabled' => true,
                'username' => $username,
                'password_encrypted' => Crypt::encryptString($password),
                'status' => self::STATUS_ACTIVE,
                'created_at' => now()->toISOString(),
                'last_error' => null,
            ]);

            Log::info('Temporary VM credential created.', [
                'terminal_session_id' => $session->id,
                'vm_id' => $vm->id,
                'temporary_username' => $username,
                'status' => self::STATUS_ACTIVE,
            ]);

            return [
                'username' => $username,
                'password' => $password,
            ];
        } catch (Throwable $exception) {
            $session->refresh();
            $session->mergeTemporaryCredentialMetadata([
                'enabled' => true,
                'username' => $username,
                'status' => self::STATUS_FAILED,
                'last_error' => $this->safeErrorMessage($exception),
                'failed_at' => now()->toISOString(),
            ]);

            Log::warning('Temporary VM credential creation failed.', [
                'terminal_session_id' => $session->id,
                'vm_id' => $vm->id,
                'temporary_username' => $username,
                'exception_class' => $exception::class,
                'exception_message' => $this->safeErrorMessage($exception),
            ]);

            throw new RuntimeException($this->clearFailureMessage($exception), previous: $exception);
        }
    }

    public function disableTemporaryUser(?Vm $vm, TerminalSession $session): void
    {
        $session->loadMissing('vm');
        $vm ??= $session->vm;
        $username = $session->temporaryUsername();

        if (! $vm || ! $username) {
            return;
        }

        if (in_array($session->temporaryCredentialStatus(), [self::STATUS_CLEANED, self::STATUS_ALREADY_REMOVED], true)) {
            return;
        }

        $session->mergeTemporaryCredentialMetadata([
            'status' => self::STATUS_CLEANUP_RUNNING,
            'cleanup_started_at' => now()->toISOString(),
        ]);

        try {
            $ssh = $this->connect($session, $this->cleanupTimeoutSeconds());

            if (! $this->linuxUserExists($ssh, $username, cleanup: true)) {
                $session->refresh();
                $session->mergeTemporaryCredentialMetadata([
                    'status' => self::STATUS_ALREADY_REMOVED,
                    'disabled_at' => now()->toISOString(),
                    'cleaned_at' => now()->toISOString(),
                    'last_error' => null,
                ]);

                Log::info('Temporary VM credential already removed.', [
                    'terminal_session_id' => $session->id,
                    'vm_id' => $vm->id,
                    'temporary_username' => $username,
                    'status' => self::STATUS_ALREADY_REMOVED,
                ]);

                return;
            }

            $this->runOptional($ssh, 'cleanup_pkill', 'sudo -n pkill -KILL -u '.escapeshellarg($username));
            $this->runOptional($ssh, 'cleanup_usermod', 'sudo -n usermod -L -s /usr/sbin/nologin '.escapeshellarg($username));
            $delete = $this->run($ssh, 'cleanup_userdel', 'sudo -n userdel -r '.escapeshellarg($username));

            $removed = $delete->successful || $this->outputIndicatesMissingUser($delete->combinedOutput());
            $status = $removed ? self::STATUS_CLEANED : self::STATUS_CLEANUP_FAILED;
            $metadata = [
                'status' => $status,
                'disabled_at' => now()->toISOString(),
                'cleaned_at' => $removed ? now()->toISOString() : null,
                'last_error' => $removed ? null : $this->sanitizeOutput($delete->combinedOutput()),
            ];

            $session->refresh();
            $session->mergeTemporaryCredentialMetadata($metadata);

            Log::info('Temporary VM credential disabled.', [
                'terminal_session_id' => $session->id,
                'vm_id' => $vm->id,
                'temporary_username' => $username,
                'status' => $status,
            ]);
        } catch (Throwable $exception) {
            $session->refresh();
            $session->mergeTemporaryCredentialMetadata([
                'status' => self::STATUS_CLEANUP_FAILED,
                'disabled_at' => now()->toISOString(),
                'last_error' => $this->safeErrorMessage($exception),
            ]);

            Log::warning('Temporary VM credential cleanup failed.', [
                'terminal_session_id' => $session->id,
                'vm_id' => $vm->id,
                'temporary_username' => $username,
                'exception_class' => $exception::class,
                'exception_message' => $this->safeErrorMessage($exception),
            ]);
        }
    }

    public function generateUsername(TerminalSession $session): string
    {
        $sessionPart = preg_replace('/[^0-9a-z]/i', '', (string) $session->id) ?: 's';
        $suffix = bin2hex(random_bytes(2));

        return strtolower('jit_'.$sessionPart.'_'.$suffix);
    }

    public function generatePassword(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function connect(TerminalSession $session, ?int $timeoutSeconds = null): SSH2
    {
        $credentials = $this->sshCommandService->resolveCredentials($session);

        if (($credentials['password'] ?? null) === null && ($credentials['private_key'] ?? null) === null) {
            throw new RuntimeException('SSH credentials are not configured for Shared Practical VM management.');
        }

        $timeout = $timeoutSeconds ?? max(1, min((int) config('services.terminal.command_timeout', 10), 30));
        $ssh = new SSH2($session->ssh_host, (int) ($credentials['port'] ?? $session->ssh_port ?? 22), $timeout);
        $ssh->setTimeout($timeout);

        $credential = ($credentials['private_key'] ?? null) !== null
            ? PublicKeyLoader::loadPrivateKey($credentials['private_key'])
            : $credentials['password'];

        if (! $ssh->login($credentials['username'], $credential)) {
            throw new RuntimeException('SSH authentication failed for Shared Practical VM management credential.');
        }

        return $ssh;
    }

    private function ensureUsernameAvailable(SSH2 $ssh, string $username): void
    {
        if ($this->linuxUserExists($ssh, $username)) {
            throw new RuntimeException('Generated temporary Linux username already exists.');
        }
    }

    private function linuxUserExists(SSH2 $ssh, string $username, bool $cleanup = false): bool
    {
        return $this->run($ssh, $cleanup ? 'cleanup_getent' : 'getent', 'getent passwd '.escapeshellarg($username))->successful;
    }

    private function runRequired(SSH2 $ssh, string $label, string $command, bool $containsSecret = false): TemporaryVmCommandResult
    {
        $result = $this->run($ssh, $label, $command, $containsSecret);

        if (! $result->successful) {
            throw new RuntimeException("Temporary Linux user setup command failed: {$label}. ".$this->sanitizeOutput($result->combinedOutput()));
        }

        return $result;
    }

    private function runOptional(SSH2 $ssh, string $label, string $command): TemporaryVmCommandResult
    {
        return $this->run($ssh, $label, $command);
    }

    private function run(SSH2 $ssh, string $label, string $command, bool $containsSecret = false): TemporaryVmCommandResult
    {
        $ssh->setTimeout(str_starts_with($label, 'cleanup_') ? $this->cleanupTimeoutSeconds() : max(1, min((int) config('services.terminal.command_timeout', 10), 30)));
        $stdout = $ssh->exec($command);
        $stderr = (string) $ssh->getStdError();
        $exitCode = $ssh->getExitStatus();
        $timedOut = $ssh->isTimeout();
        $successful = $stdout !== false && ! $timedOut && ($exitCode === null || $exitCode === 0);

        Log::debug('Temporary VM credential command completed.', [
            'operation' => $label,
            'exit_code' => is_int($exitCode) ? $exitCode : null,
            'successful' => $successful,
            'timed_out' => $timedOut,
            'stdout' => $containsSecret ? '[redacted]' : $this->sanitizeOutput(is_string($stdout) ? $stdout : ''),
            'stderr' => $containsSecret ? '[redacted]' : $this->sanitizeOutput($stderr),
        ]);

        return new TemporaryVmCommandResult(
            successful: $successful,
            exitCode: is_int($exitCode) ? $exitCode : null,
            stdout: is_string($stdout) ? $stdout : '',
            stderr: $stderr,
            timedOut: $timedOut,
        );
    }

    private function clearFailureMessage(Throwable $exception): string
    {
        $message = $this->safeErrorMessage($exception);

        if (str_contains($message, 'sudo') || str_contains($message, 'permission') || str_contains($message, 'not in the sudoers')) {
            return 'Temporary Linux user could not be created. Check limited sudo permissions on the Shared Practical VM management credential.';
        }

        return 'Temporary Linux user could not be created: '.$message;
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        return $this->sanitizeOutput($exception->getMessage());
    }

    private function sanitizeOutput(string $output): string
    {
        return trim(str($output)->replace("\0", '')->limit(1000, "\n[output truncated]")->toString());
    }

    private function cleanupTimeoutSeconds(): int
    {
        return max(1, min((int) config('services.terminal.cleanup_timeout', 5), 10));
    }

    private function outputIndicatesMissingUser(string $output): bool
    {
        $output = strtolower($output);

        return str_contains($output, 'does not exist')
            || str_contains($output, 'no such user')
            || str_contains($output, 'not found');
    }
}
