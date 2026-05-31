<?php

namespace App\Services;

class TerminalPtyProcess
{
    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    public function __construct(
        private $process,
        private array $pipes,
        private readonly ?string $temporaryPrivateKeyPath = null,
    ) {
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    public function write(string $input): void
    {
        if (! isset($this->pipes[0]) || ! is_resource($this->pipes[0])) {
            throw new \RuntimeException('PTY stdin pipe is not available.');
        }

        fwrite($this->pipes[0], $input);
    }

    public function readAvailable(): string
    {
        $output = '';

        foreach ([1, 2] as $index) {
            if (! isset($this->pipes[$index]) || ! is_resource($this->pipes[$index])) {
                continue;
            }

            while (! feof($this->pipes[$index])) {
                $chunk = fread($this->pipes[$index], 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $output .= $chunk;
            }
        }

        return $output;
    }

    public function isRunning(): bool
    {
        $status = proc_get_status($this->process);

        return (bool) ($status['running'] ?? false);
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        if ($this->temporaryPrivateKeyPath !== null && is_file($this->temporaryPrivateKeyPath)) {
            @unlink($this->temporaryPrivateKeyPath);
        }
    }
}
