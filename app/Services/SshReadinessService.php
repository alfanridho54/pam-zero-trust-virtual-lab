<?php

namespace App\Services;

class SshReadinessService
{
    public function waitUntilReachable(string $host, int $port, ?int $attempts = null, ?int $delayMilliseconds = null, ?float $timeoutSeconds = null): bool
    {
        $host = trim($host);

        if ($host === '' || $port < 1 || $port > 65535) {
            return false;
        }

        $attempts ??= max(1, (int) config('services.terminal.ssh_ready_attempts', 6));
        $delayMilliseconds ??= max(0, (int) config('services.terminal.ssh_ready_delay_ms', 500));
        $timeoutSeconds ??= max(0.1, (float) config('services.terminal.ssh_ready_timeout', 1.0));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->canConnect($host, $port, $timeoutSeconds)) {
                return true;
            }

            if ($attempt < $attempts && $delayMilliseconds > 0) {
                usleep($delayMilliseconds * 1000);
            }
        }

        return false;
    }

    protected function canConnect(string $host, int $port, float $timeoutSeconds): bool
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeoutSeconds);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
