<?php

namespace App\Console\Commands;

use App\Models\TerminalSession;
use App\Models\User;
use App\Services\TerminalWebSocketCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class TerminalWebSocketServer extends Command
{
    protected $signature = 'terminal:websocket {--host=0.0.0.0} {--port=8090}';

    protected $description = 'Run the PAM terminal WebSocket command server.';

    /** @var array<int, array{socket: resource, session?: TerminalSession, user?: User, authed: bool}> */
    private array $clients = [];

    public function handle(TerminalWebSocketCommandService $commandService): int
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

                $this->handlePayload($clientId, $payload, $commandService);
            }
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

    private function handlePayload(int $clientId, string $payload, TerminalWebSocketCommandService $commandService): void
    {
        $message = json_decode($payload, true);

        if (! is_array($message)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Invalid websocket payload.']);

            return;
        }

        if (($message['type'] ?? null) === 'auth') {
            $this->authenticate($clientId, (string) ($message['ticket'] ?? ''));

            return;
        }

        if (! ($this->clients[$clientId]['authed'] ?? false)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket is not authenticated.']);

            return;
        }

        if (($message['type'] ?? null) !== 'command') {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Unsupported terminal websocket message.']);

            return;
        }

        $command = trim((string) ($message['command'] ?? ''));

        if ($command === '') {
            return;
        }

        $this->executeCommand($clientId, $command, $commandService);
    }

    private function authenticate(int $clientId, string $ticket): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Invalid terminal websocket ticket.']);
            $this->disconnect($clientId);

            return;
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket ticket expired.']);
            $this->disconnect($clientId);

            return;
        }

        $session = TerminalSession::with('vm')->where('session_uuid', $payload['session_uuid'] ?? null)->first();
        $user = User::find($payload['user_id'] ?? null);

        if (! $session || ! $user || ! $session->isOwnedBy($user)) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal websocket session denied.']);
            $this->disconnect($clientId);

            return;
        }

        $session->expireIfPastDue();

        if ($session->isEnded() || $session->isExpired()) {
            $this->sendClient($clientId, ['type' => 'error', 'message' => 'Terminal session is not active.']);
            $this->disconnect($clientId);

            return;
        }

        $this->clients[$clientId]['session'] = $session;
        $this->clients[$clientId]['user'] = $user;
        $this->clients[$clientId]['authed'] = true;

        $this->sendClient($clientId, [
            'type' => 'ready',
            'prompt' => ($session->ssh_username ?: 'student').'@'.($session->ssh_host ?: 'virtual-lab'),
        ]);
    }

    private function executeCommand(int $clientId, string $command, TerminalWebSocketCommandService $commandService): void
    {
        /** @var TerminalSession $session */
        $session = $this->clients[$clientId]['session'];
        /** @var User $user */
        $user = $this->clients[$clientId]['user'];

        $this->sendClient($clientId, ['type' => 'running', 'command' => $command]);
        $result = $commandService->run($session, $user, $command);

        if ($result->type !== 'ignored') {
            $this->sendClient($clientId, $result->toPayload());
        }
    }

    /** @param resource $socket */
    private function receive($socket): ?string
    {
        $data = @fread($socket, 8192);

        if ($data === '' || $data === false) {
            return null;
        }

        $length = ord($data[1]) & 127;
        $offset = 2;

        if ($length === 126) {
            $length = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            $length = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

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
        unset($this->clients[$clientId]);
    }
}
