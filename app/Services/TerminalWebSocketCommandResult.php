<?php

namespace App\Services;

final readonly class TerminalWebSocketCommandResult
{
    public function __construct(
        public string $type,
        public string $command,
        public ?string $status,
        public string $output,
        public ?string $sessionStatus = null,
    ) {
    }

    public function toPayload(): array
    {
        return array_filter([
            'type' => $this->type,
            'command' => $this->command,
            'status' => $this->status,
            'output' => $this->output,
            'session_status' => $this->sessionStatus,
        ], fn ($value) => $value !== null);
    }
}
