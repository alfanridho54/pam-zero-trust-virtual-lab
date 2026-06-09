<?php

namespace App\Services;

final readonly class TemporaryVmCommandResult
{
    public function __construct(
        public bool $successful,
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
    ) {
    }

    public function combinedOutput(): string
    {
        return trim($this->stdout."\n".$this->stderr);
    }
}
