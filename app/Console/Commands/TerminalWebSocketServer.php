<?php

namespace App\Console\Commands;

use App\Models\TerminalSession;
use App\Models\User;
use App\Services\TerminalPtyProcess;
use App\Services\TerminalWebSocketCommandService;
use App\Services\TerminalPtySessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class TerminalWebSocketServer extends Command
{
    protected $signature = 'terminal:websocket {--host=0.0.0.0} {--port=8090}';

    protected $description = 'Run the PAM terminal WebSocket command server.';

    /** @var array<int, array{socket: resource, session?: TerminalSession, user?: User, authed: bool, mode?: string, pty?: TerminalPtyProcess}> */
    private array $clients = [];

    /**
     * Server WebSocket ringan untuk terminal interaktif PAM.
     * Transport ini tidak mengeksekusi command langsung; semua command diteruskan ke service terpantau.
     */
    public function handle(TerminalWebSocketCommandService $commandService, TerminalPtySessionService $ptyService): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if (! $server) {
            $this->error("Unable to start terminal WebSocket server: {$errstr} ({$errno})");

            return self::FAILURE;
        }

        stream_set_blocking($server, false);
        $this->info("Terminal WebSocket server listening on {$host}:{$port}");

        while (true) {
            $read = [$server, ...array_column($this->clients, 'socket')];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $socket) {
                if ($socket === $server) {
                    $this->acceptClient($server);

                    continue;
                }

                $clientId = (int) $socket;
                $payload = $this->receive($socket);

                if ($payload === null) {
                    $this->disconnect($clientId);

                    continue;
                }

                $this->handlePayload($clientId, $payload, $commandService, $ptyService);
            }

            $this->pumpPtyClients($ptyService);
        }
    }

    /** @param resource $server */
    private function acceptClient($server): void
    {
        $socket = @stream_socket_accept($server, 0);

        if (! $socket) {
            return;
        }

        $headers = fread($socket, 4096);

        if (! preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $headers, $matches)) {
            fclose($socket);

            return;
        }

        // Handshake minimal WebSocket; autentikasi PAM dilakukan setelah client mengirim ticket.
        $accept = base64_encode(sha1(trim($matches[1]).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        fwrite($socket, "HTTP/1.1 101 Switching Protocols\r\n");
        fwrite($socket, "Upgrade: websocket\r\n");
        fwrite($socket, "Connection: Upgrade\r\n");
        fwrite($socket, "Sec-WebSocket-Accept: {$accept}\r\n\r\n");
        stream_set_blocking($socket, false);

        $this->clients[(int) $socket] = [
            'socket' => $socket,
            'authed' => false,
        ];

        $this->send($socket, [
            'type' => 'hello',
            'message' => 'PAM terminal websocket connected.',
        ]);
    }

    private function handlePayload(int $clientId, string $payload, TerminalWebSocketCommandService $commandService, TerminalPtySessionService $ptyService): void
    {
        $message = json_decode($payload, true);

        if (! is_array($message)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Invalid websocket payload.']);

            return;
        }

        if (($message['type'] ?? null) === 'auth') {
            // Client harus membuktikan ticket sebelum command apa pun diterima.
            $this->authenticate($clientId, (string) ($message['ticket'] ?? ''), $ptyService);

            return;
        }

        if (! ($this->clients[$clientId]['authed'] ?? false)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket is not authenticated.']);

            return;
        }

        $mode = $this->clients[$clientId]['mode'] ?? 'command';
        $type = $message['type'] ?? null;

        if ($mode === 'pty') {
            if ($type === 'command') {
                $command = trim((string) ($message['command'] ?? ''));

                if ($command === '') {
                    return;
                }

                $this->writePty($clientId, $command."\n", $ptyService, true);

                return;
            }

            if ($type === 'input') {
                $this->writePty($clientId, (string) ($message['input'] ?? ''), $ptyService);

                return;
            }

            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Unsupported PTY websocket message.']);

            return;
        }

        if ($type !== 'command') {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Unsupported terminal websocket message.']);

            return;
        }

        $command = trim((string) ($message['command'] ?? ''));

        if ($command === '') {
            return;
        }

        $this->executeCommand($clientId, $command, $commandService);
    }

    private function authenticate(int $clientId, string $ticket, TerminalPtySessionService $ptyService): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Invalid terminal websocket ticket.']);
            $this->disconnect($clientId);

            return;
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            // Ticket pendek mengurangi risiko reuse jika token WebSocket bocor dari browser.
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket ticket expired.']);
            $this->disconnect($clientId);

            return;
        }

        $session = TerminalSession::with('vm.practicalAccesses')->where('session_uuid', $payload['session_uuid'] ?? null)->first();
        $user = User::find($payload['user_id'] ?? null);

        if (! $session || ! $user || ! $session->canBeAccessedBy($user)) {
            // Ticket hanya valid untuk pemilik sesi; user lain tidak bisa menumpang session_uuid.
            $this->logWebSocketSessionDenied($session, $user);
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket session denied.']);
            $this->disconnect($clientId);

            return;
        }

        $session->expireIfPastDue();

        if ($session->isEnded() || $session->isExpired()) {
            // Revoke/expire dari dashboard langsung memutus terminal interaktif berikutnya.
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal session is not active.']);
            $this->disconnect($clientId);

            return;
        }

        $mode = $ptyService->canOpen($session, $user) ? 'pty' : 'command';
        $this->clients[$clientId]['session'] = $session;
        $this->clients[$clientId]['user'] = $user;
        $this->clients[$clientId]['authed'] = true;
        $this->clients[$clientId]['mode'] = $mode;

        if ($mode === 'pty') {
            try {
                $this->clients[$clientId]['pty'] = $ptyService->open($session, $user);
            } catch (Throwable $exception) {
                Log::warning('PTY terminal session failed to open.', [
                    'terminal_session_id' => $session->id,
                    'vm_id' => $session->vm_id,
                    'user_id' => $user->id,
                    'host' => $session->ssh_host,
                    'username' => $session->ssh_username,
                    'port' => $session->ssh_port,
                    'pty_method' => 'proc_open_ssh_tt',
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ]);

                $this->sendClient($clientId, ['type' => 'error', 'message' => 'PTY terminal could not be opened.']);
                $this->disconnect($clientId);

                return;
            }
        }

        $this->sendClient($clientId, [
            'type' => 'ready',
            'mode' => $mode,
            'prompt' => ($session->ssh_username ?: 'student').'@'.($session->ssh_host ?: 'virtual-lab'),
        ]);

        if ($mode === 'pty') {
            $this->pumpPtyClient($clientId, $ptyService);
        }
    }

    private function executeCommand(int $clientId, string $command, TerminalWebSocketCommandService $commandService): void
    {
        /** @var TerminalSession $session */
        $session = $this->clients[$clientId]['session'];
        /** @var User $user */
        $user = $this->clients[$clientId]['user'];

        $this->sendClient($clientId, ['type' => 'running', 'command' => $command]);
        // Forward command melalui service PAM agar policy, audit log, dan redaksi output tetap berjalan.
        $result = $commandService->run($session, $user, $command);

        if ($result->type !== 'ignored') {
            $this->sendClient($clientId, $result->toPayload());
        }
    }

    private function writePty(int $clientId, string $input, TerminalPtySessionService $ptyService, bool $logCommand = false): void
    {
        /** @var TerminalSession $session */
        $session = $this->clients[$clientId]['session'];
        /** @var User $user */
        $user = $this->clients[$clientId]['user'];
        /** @var TerminalPtyProcess|null $ptyProcess */
        $ptyProcess = $this->clients[$clientId]['pty'] ?? null;

        if (! $ptyProcess) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'PTY terminal is not available.']);
            $this->disconnect($clientId);

            return;
        }

        try {
            $ptyService->write($session, $user, $ptyProcess, $input, $logCommand);
            $this->pumpPtyClient($clientId, $ptyService);
        } catch (Throwable $exception) {
            Log::warning('PTY terminal write failed.', [
                'terminal_session_id' => $session->id,
                'vm_id' => $session->vm_id,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            $this->sendClient($clientId, ['type' => 'error', 'message' => 'PTY terminal session is no longer available.']);
            $this->disconnect($clientId);
        }
    }

    private function pumpPtyClients(TerminalPtySessionService $ptyService): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            if (($this->clients[$clientId]['mode'] ?? null) === 'pty') {
                $this->pumpPtyClient($clientId, $ptyService);
            }
        }
    }

    private function pumpPtyClient(int $clientId, TerminalPtySessionService $ptyService): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        /** @var TerminalSession $session */
        $session = $this->clients[$clientId]['session'];
        /** @var User $user */
        $user = $this->clients[$clientId]['user'];
        /** @var TerminalPtyProcess|null $ptyProcess */
        $ptyProcess = $this->clients[$clientId]['pty'] ?? null;

        if (! $ptyProcess) {
            return;
        }

        if (! $ptyService->canOpen($session->refresh(), $user)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal session is not active.']);
            $this->disconnect($clientId);

            return;
        }

        $output = $ptyService->readAvailable($ptyProcess);

        if ($output !== '') {
            $this->sendClient($clientId, ['type' => 'pty_output', 'output' => $output]);
        }
    }

    private function logWebSocketSessionDenied(?TerminalSession $session, ?User $user): void
    {
        $session?->loadMissing('vm.practicalAccesses');
        $vm = $session?->vm;
        $practicalAccessExists = ($session && $user && $vm) ? $vm->hasPracticalAccess($user) : false;

        Log::warning('Terminal websocket session denied.', [
            'auth_id' => $user?->id,
            'session_id' => $session?->id,
            'session_user_id' => $session?->user_id,
            'session_vm_id' => $session?->vm_id,
            'vm_user_id' => $vm?->user_id,
            'shared_practical' => (bool) ($vm?->metadata['shared_practical'] ?? false),
            'practical_access_exists' => $practicalAccessExists,
            'can_be_accessed_by' => $session && $user ? $session->canBeAccessedBy($user) : false,
        ]);
    }

    /** @param resource $socket */
    private function receive($socket): ?string
    {
        $data = @fread($socket, 8192);

        if ($data === '' || $data === false) {
            return null;
        }

        if (strlen($data) < 2) {
            Log::warning('Invalid websocket frame received.', [
                'reason' => 'frame_header_too_short',
                'bytes_received' => strlen($data),
            ]);

            return null;
        }

        $length = ord($data[1]) & 127;
        $offset = 2;

        if ($length === 126) {
            if (strlen($data) < 4) {
                Log::warning('Invalid websocket frame received.', [
                    'reason' => 'extended_16_length_missing',
                    'bytes_received' => strlen($data),
                ]);

                return null;
            }

            $lengthBytes = unpack('nlength', substr($data, 2, 2));
            $length = (int) ($lengthBytes['length'] ?? 0);
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($data) < 10) {
                Log::warning('Invalid websocket frame received.', [
                    'reason' => 'extended_64_length_missing',
                    'bytes_received' => strlen($data),
                ]);

                return null;
            }

            $lengthBytes = unpack('Jlength', substr($data, 2, 8));
            $length = (int) ($lengthBytes['length'] ?? 0);
            $offset = 10;
        }

        if (strlen($data) < $offset + 4 + $length) {
            Log::warning('Invalid websocket frame received.', [
                'reason' => 'payload_too_short',
                'bytes_received' => strlen($data),
                'expected_bytes' => $offset + 4 + $length,
                'payload_length' => $length,
            ]);

            return null;
        }

        // Browser mengirim frame termask; payload dibuka di sini sebelum diproses sebagai JSON.
        $mask = substr($data, $offset, 4);
        $payload = substr($data, $offset + 4, $length);
        $decoded = '';

        for ($i = 0; $i < $length; $i++) {
            $decoded .= $payload[$i] ^ $mask[$i % 4];
        }

        return $decoded;
    }

    /** @param resource $socket */
    private function send($socket, array $payload): void
    {
        $encoded = json_encode($payload);
        $length = strlen($encoded);
        $header = chr(129);

        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126).pack('n', $length);
        } else {
            $header .= chr(127).pack('J', $length);
        }

        @fwrite($socket, $header.$encoded);
    }

    private function sendClient(int $clientId, array $payload): void
    {
        if (isset($this->clients[$clientId])) {
            $this->send($this->clients[$clientId]['socket'], $payload);
        }
    }

    private function disconnect(int $clientId): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        @fclose($this->clients[$clientId]['socket']);
        if (($this->clients[$clientId]['pty'] ?? null) instanceof TerminalPtyProcess) {
            $this->clients[$clientId]['pty']->close();
        }
        unset($this->clients[$clientId]);
    }
}
