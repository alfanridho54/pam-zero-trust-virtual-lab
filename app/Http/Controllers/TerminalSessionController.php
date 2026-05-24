<?php

namespace App\Http\Controllers;

use App\Enums\TerminalSessionStatus;
use App\Models\AuditLog;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Vm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TerminalSessionController extends Controller
{
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

        return view('terminal-sessions.show', [
            'terminalSession' => $terminalSession,
        ]);
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

    private function makeSessionToken(): string
    {
        do {
            $token = Str::random(80);
        } while (TerminalSession::where('session_token', $token)->exists());

        return $token;
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
}
