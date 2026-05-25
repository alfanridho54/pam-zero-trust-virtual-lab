<?php

namespace App\Http\Controllers;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\SshCommandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\View\View;

class TerminalSessionController extends Controller
{
    private const DEFAULT_TEST_COMMAND = 'whoami && hostname && uptime';
    private const OUTPUT_EXCERPT_LIMIT = 4000;

    public function store(Request $request, Vm $vm): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        $authorization = Gate::forUser($user)->inspect('create', [TerminalSession::class, $vm]);

        if ($authorization->denied()) {
            $this->audit($user, $vm, 'terminal.session.denied', $authorization->message() ?: 'Terminal session ditolak.');

            return back()->with('error', $authorization->message() ?: 'Anda tidak memiliki akses ke VM ini.');
        }

        $terminalSession = TerminalSession::create([
            'session_token' => $this->makeSessionToken(),
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => $vm->proxmoxVmid(),
            'ssh_host' => $this->targetHostFor($vm),
            'ssh_port' => $this->targetPortFor($vm),
            'ssh_username' => $this->targetUsernameFor($vm),
            'status' => TerminalSessionStatus::Pending,
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [
                'source' => 'web-dashboard',
                'ssh_ready' => false,
            ],
        ]);

        $this->audit($user, $vm, 'terminal.session.created', 'Terminal session placeholder dibuat.', [
            'terminal_session_id' => $terminalSession->id,
            'expires_at' => $terminalSession->expires_at?->toISOString(),
        ]);

        return redirect()
            ->route('terminal-sessions.show', $terminalSession)
            ->with('status', 'Terminal session dibuat. SSH belum diaktifkan.');
    }

    public function show(Request $request, TerminalSession $terminalSession): View
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        Gate::forUser($user)->authorize('view', $terminalSession);

        $terminalSession->load(['user', 'vm']);
        $terminalSession->expireIfPastDue();
        $commandLogs = $terminalSession->commandLogs()
            ->recent()
            ->limit(10)
            ->get();

        return view('terminal-sessions.show', [
            'terminalSession' => $terminalSession,
            'commandLogs' => $commandLogs,
            'defaultCommand' => self::DEFAULT_TEST_COMMAND,
            'terminalWebSocketUrl' => config('services.terminal.websocket_url'),
            'terminalWebSocketTicket' => $this->makeWebSocketTicket($terminalSession, $user),
        ]);
    }

    public function executeCommand(Request $request, TerminalSession $terminalSession, SshCommandService $sshCommandService): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        $terminalSession->load('vm');

        Gate::forUser($user)->authorize('view', $terminalSession);

        $terminalSession->expireIfPastDue();

        $command = trim((string) $request->input('command', self::DEFAULT_TEST_COMMAND));
        $command = $command !== '' ? $command : self::DEFAULT_TEST_COMMAND;

        if (mb_strlen($command) > 1000) {
            $commandLog = $this->createCommandLog($terminalSession, $user, $command);
            $commandLog->markBlocked('Command terlalu panjang untuk POC terminal.');
            $this->touchActivityWhenOpen($terminalSession);

            return back()->with('error', 'Command terlalu panjang untuk POC terminal.');
        }

        $authorization = Gate::forUser($user)->inspect('execute', [CommandLog::class, $terminalSession, $command]);

        if ($authorization->denied()) {
            $commandLog = $this->createCommandLog($terminalSession, $user, $command);
            $commandLog->markBlocked($authorization->message() ?: CommandLog::blockedReasonFor($command));
            $this->touchActivityWhenOpen($terminalSession);

            return back()->with('error', $authorization->message() ?: 'Command diblokir oleh policy terminal.');
        }

        $commandLog = $this->createCommandLog($terminalSession, $user, $command);

        if ($terminalSession->isPending()) {
            $terminalSession->forceFill([
                'status' => TerminalSessionStatus::Active,
                'metadata' => [
                    ...($terminalSession->metadata ?? []),
                    'ssh_ready' => true,
                    'transport' => 'ssh-command-poc',
                ],
            ])->save();
        }

        try {
            $result = $sshCommandService->execute($terminalSession, $command);
            $outputExcerpt = $this->outputExcerpt($result->output ?: $result->error ?: 'SSH command execution failed.');

            $result->successful
                ? $commandLog->markSucceeded($result->exitCode, $result->durationMs, $outputExcerpt)
                : $commandLog->markFailed($result->exitCode, $result->durationMs, $outputExcerpt);
        } catch (Throwable) {
            $commandLog->markFailed(null, null, 'SSH command execution failed.');
        }

        $terminalSession->touchActivity();

        return back()->with(
            $commandLog->isSucceeded() ? 'status' : 'error',
            $commandLog->isSucceeded() ? 'Command berhasil dijalankan.' : 'Command gagal dijalankan.',
        );
    }

    public function destroy(Request $request, TerminalSession $terminalSession): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        Gate::forUser($user)->authorize('close', $terminalSession);

        if (! $terminalSession->isEnded()) {
            $terminalSession->close();
        }

        $this->audit($user, $terminalSession->vm, 'terminal.session.closed', 'Terminal session ditutup.', [
            'terminal_session_id' => $terminalSession->id,
        ]);

        return redirect()
            ->route('dashboard.vms')
            ->with('status', 'Terminal session ditutup.');
    }

    public function revoke(Request $request, TerminalSession $terminalSession): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke session terminal ini.');

        $terminalSession->load(['user', 'vm']);

        Gate::forUser($user)->authorize('revoke', $terminalSession);

        $revokedAt = now();

        $terminalSession->forceFill([
            'status' => TerminalSessionStatus::Revoked,
            'ended_at' => $revokedAt,
            'last_activity_at' => $revokedAt,
        ])->save();

        $this->audit($user, $terminalSession->vm, 'terminal_session.revoked', 'Admin melakukan revoke terminal session.', [
            'terminal_session_id' => $terminalSession->id,
            'terminal_session_uuid' => $terminalSession->session_uuid,
            'target_user_id' => $terminalSession->user_id,
            'target_user_email' => $terminalSession->user?->email,
        ]);

        return back()->with('status', 'Terminal session berhasil di-revoke.');
    }

    private function makeSessionToken(): string
    {
        do {
            $token = Str::random(80);
        } while (TerminalSession::where('session_token', $token)->exists());

        return $token;
    }

    private function makeWebSocketTicket(TerminalSession $terminalSession, User $user): ?string
    {
        if ($terminalSession->isEnded() || $terminalSession->isExpired()) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'session_uuid' => $terminalSession->session_uuid,
            'user_id' => $user->id,
            'expires_at' => now()
                ->addSeconds((int) config('services.terminal.websocket_ticket_ttl', 600))
                ->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function targetHostFor(Vm $vm): string
    {
        return (string) (
            $vm->metadata['target_host']
            ?? $vm->metadata['ssh_host']
            ?? $vm->metadata['ip']
            ?? $vm->metadata['ip_address']
            ?? config('services.terminal.target_host')
            ?? $vm->node
        );
    }

    private function targetPortFor(Vm $vm): int
    {
        return (int) (
            $vm->metadata['target_port']
            ?? $vm->metadata['ssh_port']
            ?? config('services.terminal.target_port')
            ?? 22
        );
    }

    private function targetUsernameFor(Vm $vm): string
    {
        return (string) (
            $vm->metadata['target_username']
            ?? $vm->metadata['ssh_username']
            ?? config('services.terminal.target_username')
            ?? 'student'
        );
    }

    private function resolveDashboardUser(Request $request): ?User
    {
        if ($request->user()) {
            return $request->user();
        }

        $email = $request->headers->get('Cf-Access-Authenticated-User-Email')
            ?: $request->headers->get('X-Forwarded-Email');

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
                'password' => bcrypt(str()->random(40)),
                'role' => 'student',
            ],
        );
    }

    private function audit(User $user, ?Vm $vm, string $action, string $description, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'vm_id' => $vm?->id,
            'action' => $action,
            'description' => $description,
            'metadata' => ['source' => 'terminal-session', ...$metadata],
        ]);
    }

    private function createCommandLog(TerminalSession $terminalSession, User $user, string $command): CommandLog
    {
        return CommandLog::create([
            'terminal_session_id' => $terminalSession->id,
            'user_id' => $user->id,
            'vm_id' => $terminalSession->vm_id,
            'command' => $command,
            'status' => CommandLogStatus::Allowed,
            'executed_at' => now(),
            'metadata' => [
                'source' => 'terminal-session-poc',
                'client_ip' => request()->ip(),
            ],
        ]);
    }

    private function outputExcerpt(string $output): string
    {
        $password = config('services.terminal.ssh_password');
        $excerpt = str($output)
            ->replace("\0", '')
            ->limit(self::OUTPUT_EXCERPT_LIMIT, "\n[output truncated]")
            ->toString();

        if (is_string($password) && $password !== '') {
            $excerpt = str_replace($password, '[redacted]', $excerpt);
        }

        return $excerpt;
    }

    private function touchActivityWhenOpen(TerminalSession $terminalSession): void
    {
        if (! $terminalSession->isEnded() && ! $terminalSession->isExpired()) {
            $terminalSession->touchActivity();
        }
    }
}
