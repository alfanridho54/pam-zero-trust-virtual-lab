<?php

namespace App\Services;

final readonly class TerminalWebSocketCommandResult
{
    public function __construct(
        public string $type,
        public string $command,
        public ?string $status,
        public string $output,
    ) {
    }

    public function toPayload(): array
    {
        return array_filter([
            'type' => $this->type,
            'command' => $this->command,
            'status' => $this->status,
            'output' => $this->output,
        ], fn ($value) => $value !== null);
    }
}
