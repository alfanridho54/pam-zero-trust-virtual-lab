<?php

namespace App\Services;

final readonly class SshCommandResult
{
    public function __construct(
        public bool $successful,
        public ?int $exitCode,
        public int $durationMs,
        public string $output,
        public ?string $error = null,
    ) {
    }
}
