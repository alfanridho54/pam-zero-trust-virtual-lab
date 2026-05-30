<?php

namespace App\Http\Controllers;

use App\Enums\CommandLogStatus;
use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\CommandLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use App\Services\ProxmoxService;
use App\Services\SshCommandService;
use App\Services\SshReadinessService;
use App\Services\VmSshHostRefreshService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class TerminalSessionController extends Controller
{
    private const DEFAULT_TEST_COMMAND = 'whoami && hostname && uptime';

    private const OUTPUT_EXCERPT_LIMIT = 4000;

    /**
     * Membuka lifecycle sesi PAM untuk VM yang sudah lolos policy ownership.
     * Sesi dibuat sebagai pending agar akses SSH baru aktif saat command pertama dijalankan.
     */
    public function store(Request $request, Vm $vm, ProxmoxService $proxmox, SshReadinessService $sshReadiness, VmSshHostRefreshService $sshHostRefresh): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        $authorization = Gate::forUser($user)->inspect('create', [TerminalSession::class, $vm]);

        if ($authorization->denied()) {
            $this->audit($user, $vm, 'terminal.session.denied', $authorization->message() ?: 'Terminal session ditolak.');

            abort(403, $authorization->message() ?: 'Anda tidak memiliki akses ke VM ini.');
        }

        if ($vm->status !== 'running') {
            return back()->with('error', 'Start this VM before opening terminal access.');
        }

        $sshHost = $sshHostRefresh->refreshVm($vm) ?? $this->targetHostFor($vm);

        if ($sshHost === null) {
            $sshHost = $this->detectAndStoreGuestIp($vm, $proxmox);
        }

        if ($sshHost === null) {
            return back()->with('error', 'SSH access for this VM is not configured yet.');
        }

        $sshPort = $this->targetPortFor($vm);
        $sshReady = $vm->hasResolvedSshCredentials()
            || $sshReadiness->waitUntilReachable($sshHost, $sshPort);

        $terminalSession = TerminalSession::create([
            'session_token' => $this->makeSessionToken(),
            'user_id' => $user->id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => $vm->proxmoxVmid(),
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'ssh_username' => $this->targetUsernameFor($vm),
            'status' => TerminalSessionStatus::Pending,
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'metadata' => [
                'source' => 'web-dashboard',
                'ssh_ready' => $sshReady,
            ],
        ]);

        $this->audit($user, $vm, 'terminal.session.created', 'Terminal session placeholder dibuat.', [
            'terminal_session_id' => $terminalSession->id,
            'expires_at' => $terminalSession->expires_at?->toISOString(),
        ]);

        $message = $sshReady
            ? 'Terminal session dibuat. SSH siap digunakan.'
            : 'Terminal session dibuat. SSH VM masih disiapkan, silakan tunggu sebentar lalu refresh halaman ini.';

        return redirect()
            ->route('terminal-sessions.show', $terminalSession)
            ->with('status', $message);
    }

    public function show(Request $request, TerminalSession $terminalSession, SshReadinessService $sshReadiness, VmSshHostRefreshService $sshHostRefresh): View
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        Gate::forUser($user)->authorize('view', $terminalSession);

        $terminalSession->load(['user', 'vm']);
        // Pastikan sesi yang melewati TTL tidak lagi mendapat tiket terminal baru.
        $terminalSession->expireIfPastDue();
        $sshHostRefresh->refreshSession($terminalSession);
        $this->refreshSshReadiness($terminalSession, $sshReadiness);
        $commandLogs = $terminalSession->commandLogs()
            ->recent()
            ->limit(10)
            ->get();
        $terminalAccessError = $this->terminalTargetBlockedReason($terminalSession);

        return view('terminal-sessions.show', [
            'terminalSession' => $terminalSession,
            'commandLogs' => $commandLogs,
            'defaultCommand' => self::DEFAULT_TEST_COMMAND,
            'terminalWebSocketUrl' => config('services.terminal.websocket_url'),
            'terminalWebSocketTicket' => $terminalAccessError ? null : $this->makeWebSocketTicket($terminalSession, $user),
            'terminalAccessError' => $terminalAccessError,
        ]);
    }

    public function executeCommand(Request $request, TerminalSession $terminalSession, SshCommandService $sshCommandService, SshReadinessService $sshReadiness, VmSshHostRefreshService $sshHostRefresh): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, 'Anda tidak memiliki akses ke VM ini.');

        $terminalSession->load('vm');

        Gate::forUser($user)->authorize('view', $terminalSession);

        // Cegah sesi revoked/expired tetap mengeksekusi command melalui request lama.
        $terminalSession->expireIfPastDue();
        $sshHostRefresh->refreshSession($terminalSession);
        $this->refreshSshReadiness($terminalSession, $sshReadiness);

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
            // Command yang ditolak tetap dicatat agar SOC dapat melihat percobaan pelanggaran policy.
            $commandLog = $this->createCommandLog($terminalSession, $user, $command);
            $commandLog->markBlocked($authorization->message() ?: CommandLog::blockedReasonFor($command));
            $this->touchActivityWhenOpen($terminalSession);

            return back()->with('error', $authorization->message() ?: 'Command diblokir oleh policy terminal.');
        }

        if ($blockedReason = $this->terminalTargetBlockedReason($terminalSession)) {
            $commandLog = $this->createCommandLog($terminalSession, $user, $command);
            $commandLog->markBlocked($blockedReason);
            $this->touchActivityWhenOpen($terminalSession);

            return back()->with('error', $blockedReason);
        }

        $commandLog = $this->createCommandLog($terminalSession, $user, $command);

        if ($terminalSession->isPending()) {
            // Aktivasi dilakukan saat command pertama valid untuk menandai sesi benar-benar dipakai.
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
            // Eksekusi SSH selalu melewati service agar logging dan sanitasi output tetap konsisten.
            $result = $sshCommandService->execute($terminalSession, $command);
            $outputExcerpt = $this->outputExcerpt($result->output ?: $result->error ?: 'SSH command execution failed.', $terminalSession);

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
            ->route($this->isStudentRole($user) ? 'student.vms.index' : 'dashboard.vms')
            ->with('status', 'Terminal session ditutup.');
    }

    public function revoke(Request $request, TerminalSession $terminalSession): RedirectResponse
    {
        $user = $this->resolveDashboardUser($request);
        abort_unless($user, 403, $this->debugSessionAccessMessage(null, $terminalSession, __METHOD__));

        $terminalSession->load(['user', 'vm']);

        Gate::forUser($user)->authorize('revoke', $terminalSession);

        $revokedAt = now();

        // Revoke adalah penghentian paksa oleh admin; timestamp disamakan untuk jejak audit yang jelas.
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

    private function debugSessionAccessMessage(?User $user, TerminalSession $terminalSession, string $method): string
    {
        $terminalSession->loadMissing('vm.practicalAccesses');
        $vm = $terminalSession->vm;
        $practicalAccessExists = $user && $vm ? $vm->hasPracticalAccess($user) : false;
        $canBeAccessedBy = $user ? $terminalSession->canBeAccessedBy($user) : false;

        return 'Anda tidak memiliki akses ke session terminal ini. Debug: '
            .'auth_id='.($user?->id ?? 'null')
            .'; session_id='.$terminalSession->id
            .'; session_user_id='.$terminalSession->user_id
            .'; vm_id='.$terminalSession->vm_id
            .'; canBeAccessedBy='.($canBeAccessedBy ? 'true' : 'false')
            .'; practical_access_exists='.($practicalAccessExists ? 'true' : 'false')
            .'; returned_by='.$method;
    }

    private function makeWebSocketTicket(TerminalSession $terminalSession, User $user): ?string
    {
        if ($terminalSession->isEnded() || $terminalSession->isExpired()) {
            return null;
        }

        // Ticket terenkripsi membatasi WebSocket ke pasangan user-session dan umur pakai yang pendek.
        return Crypt::encryptString(json_encode([
            'session_uuid' => $terminalSession->session_uuid,
            'user_id' => $user->id,
            'expires_at' => now()
                ->addSeconds((int) config('services.terminal.websocket_ticket_ttl', 600))
                ->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function targetHostFor(Vm $vm): ?string
    {
        return $vm->getResolvedSshHost()
            ?? ($vm->isProvisionedStudentVm()
                ? null
                : $this->configuredStaticTargetHost($vm));
    }

    private function detectAndStoreGuestIp(Vm $vm, ProxmoxService $proxmox): ?string
    {
        if (! $vm->isProvisionedStudentVm()) {
            return null;
        }

        $vmid = $vm->proxmoxVmid();

        if ($vmid === null) {
            return null;
        }

        $ip = $proxmox->detectGuestIpv4($vm->node, $vmid);

        if ($ip === null) {
            return null;
        }

        $vm->forceFill([
            'metadata' => [
                ...($vm->metadata ?? []),
                'ssh_host' => $ip,
                'ip_address' => $ip,
            ],
        ])->save();

        return $ip;
    }

    private function targetPortFor(Vm $vm): int
    {
        return (int) (
            $vm->sshPort()
        );
    }

    private function targetUsernameFor(Vm $vm): string
    {
        return (string) (
            $vm->sshUsername()
        );
    }

    private function configuredStaticTargetHost(Vm $vm): ?string
    {
        $host = config('services.terminal.target_host') ?: $vm->node;

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }

    private function terminalTargetBlockedReason(TerminalSession $terminalSession): ?string
    {
        $terminalSession->loadMissing('vm');

        if (! $terminalSession->vm || $terminalSession->vm->trashed()) {
            return 'VM is no longer available for terminal access.';
        }

        if ($terminalSession->vm->status !== 'running') {
            return 'Start this VM before opening terminal access.';
        }

        if (! is_string($terminalSession->ssh_host) || trim($terminalSession->ssh_host) === '') {
            return 'SSH access for this VM is not configured yet.';
        }

        if (($terminalSession->metadata['ssh_ready'] ?? null) === false) {
            if ($terminalSession->vm?->hasResolvedSshCredentials()) {
                $terminalSession->forceFill([
                    'metadata' => [
                        ...($terminalSession->metadata ?? []),
                        'ssh_ready' => true,
                    ],
                ])->save();

                return null;
            }

            return 'SSH is still starting inside this VM. Please wait a moment, then refresh this terminal session.';
        }

        return null;
    }

    private function refreshSshReadiness(TerminalSession $terminalSession, SshReadinessService $sshReadiness): bool
    {
        if ($terminalSession->isEnded() || $terminalSession->isExpired()) {
            return false;
        }

        if (! array_key_exists('ssh_ready', $terminalSession->metadata ?? [])) {
            return true;
        }

        if (($terminalSession->metadata['ssh_ready'] ?? false) === true) {
            return true;
        }

        if ($terminalSession->vm?->hasResolvedSshCredentials()) {
            $terminalSession->forceFill([
                'metadata' => [
                    ...($terminalSession->metadata ?? []),
                    'ssh_ready' => true,
                ],
            ])->save();

            return true;
        }

        if (! is_string($terminalSession->ssh_host) || trim($terminalSession->ssh_host) === '' || $terminalSession->ssh_port < 1) {
            return false;
        }

        $ready = $sshReadiness->waitUntilReachable($terminalSession->ssh_host, $terminalSession->ssh_port);

        $terminalSession->forceFill([
            'metadata' => [
                ...($terminalSession->metadata ?? []),
                'ssh_ready' => $ready,
            ],
        ])->save();

        return $ready;
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

    private function isStudentRole(User $user): bool
    {
        return in_array($user->role, ['student', 'mahasiswa', 'siswa'], true);
    }

    private function audit(User $user, ?Vm $vm, string $action, string $description, array $metadata = []): void
    {
        // Semua aksi terminal masuk audit log agar alur PAM dapat ditelusuri saat monitoring/forensik.
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
        // Command dicatat sebelum hasil akhir diketahui, lalu statusnya dinaikkan ke succeeded/failed/blocked.
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

    private function outputExcerpt(string $output, ?TerminalSession $terminalSession = null): string
    {
        $password = config('services.terminal.ssh_password');
        $excerpt = str($output)
            ->replace("\0", '')
            ->limit(self::OUTPUT_EXCERPT_LIMIT, "\n[output truncated]")
            ->toString();

        foreach ([$password, $terminalSession?->vm?->sshPassword(), $terminalSession?->vm?->sshPrivateKey()] as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            // Jangan pernah menampilkan secret SSH pada dashboard SOC atau riwayat command.
            $excerpt = str_replace($secret, '[redacted]', $excerpt);
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
