<?php

namespace App\Models;

use App\Enums\CommandLogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandLog extends Model
{
    /**
     * Daftar pola command yang berisiko merusak VM atau mengambil alih akun.
     * Policy menggunakan daftar ini sebagai kontrol dasar untuk terminal praktikum.
     */
    public const STRICT_BLOCKED_COMMAND_PATTERNS = [
        '/\brm\s+-rf\s+(\/|\*)/i',
        '/\bmkfs(\.\w+)?\b/i',
        '/\bdd\s+.*\bof=\/dev\//i',
        '/\bshutdown\b/i',
        '/\breboot\b/i',
        '/\bpoweroff\b/i',
        '/\binit\s+0\b/i',
        '/\bsystemctl\s+(reboot|poweroff|halt|rescue|emergency)\b/i',
        '/\bpasswd\b/i',
        '/\buserdel\b/i',
        '/\bgroupdel\b/i',
    ];

    public const RELAXED_BLOCKED_COMMAND_PATTERNS = [
        '/\brm\s+-rf\s+(\/|\*)/i',
        '/\bmkfs(\.\w+)?\b/i',
        '/\bdd\s+.*\bof=\/dev\//i',
        '/:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:/',
        '/\b(chmod|chown)\s+(-[A-Za-z]*R[A-Za-z]*|--recursive)\s+[^;&|]*\//i',
    ];

    public const JIT_SHARED_BLOCKED_COMMAND_PATTERNS = [
        '/(?:^|[;&|]\s*)(?:sudo\s+)?(?:passwd|chpasswd|useradd|usermod|userdel|groupadd|groupmod|groupdel)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?(?:reboot|shutdown|poweroff|halt)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?systemctl\s+(?:reboot|poweroff)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?systemctl\s+restart\s+(?:networking|NetworkManager)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?netplan\s+apply(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?nmcli(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?ifconfig(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?ip\s+link\s+set(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?ip\s+addr\s+(?:add|del)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?ip\s+route\s+(?:add|del)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?(?:iptables|ufw)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?apt\s+(?:install|remove|purge)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?dpkg\s+-i(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?(?:mkfs(?:\.\w+)?|fdisk|parted)(?:\s|$)/i',
        '/(?:^|[;&|]\s*)(?:sudo\s+)?dd\s+.*\bif=/i',
        '/\brm\s+-rf\s+\/(?:\s|$)/i',
    ];

    /**
     * Backward-compatible alias for the strict shared-practical policy.
     */
    public const BLOCKED_COMMAND_PATTERNS = self::STRICT_BLOCKED_COMMAND_PATTERNS;

    protected $fillable = [
        'terminal_session_id',
        'user_id',
        'vm_id',
        'command',
        'status',
        'blocked_reason',
        'exit_code',
        'duration_ms',
        'output_excerpt',
        'executed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommandLogStatus::class,
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'executed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function terminalSession(): BelongsTo
    {
        return $this->belongsTo(TerminalSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vm(): BelongsTo
    {
        return $this->belongsTo(Vm::class);
    }

    public function scopeForSession(Builder $query, TerminalSession|int $session): Builder
    {
        return $query->where('terminal_session_id', $session instanceof TerminalSession ? $session->id : $session);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeForVm(Builder $query, Vm|int $vm): Builder
    {
        return $query->where('vm_id', $vm instanceof Vm ? $vm->id : $vm);
    }

    public function scopeWithStatus(Builder $query, CommandLogStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof CommandLogStatus ? $status->value : $status);
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->withStatus(CommandLogStatus::Blocked);
    }

    public function scopeAllowed(Builder $query): Builder
    {
        return $query->withStatus(CommandLogStatus::Allowed);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->withStatus(CommandLogStatus::Succeeded);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->withStatus(CommandLogStatus::Failed);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('executed_at');
    }

    public function isBlocked(): bool
    {
        return $this->status === CommandLogStatus::Blocked;
    }

    public function isAllowed(): bool
    {
        return $this->status === CommandLogStatus::Allowed;
    }

    public function isSucceeded(): bool
    {
        return $this->status === CommandLogStatus::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->status === CommandLogStatus::Failed;
    }

    public function isOwnedBy(User|int $user): bool
    {
        return $this->user_id === ($user instanceof User ? $user->id : $user);
    }

    public function canBeViewedBy(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        // Siswa hanya dapat melihat command miliknya pada sesi terminal miliknya.
        return $user->role === 'student'
            && $this->isOwnedBy($user)
            && $this->terminalSession?->isOwnedBy($user);
    }

    public function markSucceeded(?int $exitCode = 0, ?int $durationMs = null, ?string $outputExcerpt = null): bool
    {
        return $this->forceFill([
            'status' => CommandLogStatus::Succeeded,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'output_excerpt' => $outputExcerpt,
        ])->save();
    }

    public function markFailed(?int $exitCode = null, ?int $durationMs = null, ?string $outputExcerpt = null): bool
    {
        return $this->forceFill([
            'status' => CommandLogStatus::Failed,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'output_excerpt' => $outputExcerpt,
        ])->save();
    }

    public function markBlocked(?string $reason = null): bool
    {
        // Status blocked dipisahkan dari failed karena command tidak pernah dikirim ke SSH.
        return $this->forceFill([
            'status' => CommandLogStatus::Blocked,
            'blocked_reason' => $reason ?? self::blockedReasonFor($this->command),
        ])->save();
    }

    public static function blockedReasonFor(string $command, string $policy = 'strict'): ?string
    {
        // Pemeriksaan dibuat terpusat agar form terminal dan WebSocket memakai policy yang sama.
        foreach (self::blockedPatternsFor($policy) as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return 'Command diblokir oleh policy terminal.';
            }
        }

        return null;
    }

    public static function isCommandBlocked(string $command): bool
    {
        return self::blockedReasonFor($command) !== null;
    }

    private static function blockedPatternsFor(string $policy): array
    {
        return match ($policy) {
            'relaxed' => self::RELAXED_BLOCKED_COMMAND_PATTERNS,
            'jit_shared' => self::JIT_SHARED_BLOCKED_COMMAND_PATTERNS,
            default => self::STRICT_BLOCKED_COMMAND_PATTERNS,
        };
    }
}
