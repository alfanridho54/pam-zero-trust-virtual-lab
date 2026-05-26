<?php

namespace App\Models;

use App\Enums\TerminalSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TerminalSession extends Model
{
    protected $fillable = [
        'session_uuid',
        'session_token',
        'user_id',
        'vm_id',
        'node',
        'proxmox_id',
        'vmid',
        'ssh_host',
        'ssh_port',
        'ssh_username',
        'status',
        'client_ip',
        'user_agent',
        'started_at',
        'last_activity_at',
        'expires_at',
        'ended_at',
        'metadata',
    ];

    protected $hidden = [
        'session_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (TerminalSession $session): void {
            // UUID publik dipakai untuk referensi UI/WebSocket tanpa membuka token sesi rahasia.
            $session->session_uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TerminalSessionStatus::class,
            'ssh_port' => 'integer',
            'vmid' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vm(): BelongsTo
    {
        return $this->belongsTo(Vm::class);
    }

    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', TerminalSessionStatus::Active)
            ->whereNull('ended_at');
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeForVm(Builder $query, Vm|int $vm): Builder
    {
        return $query->where('vm_id', $vm instanceof Vm ? $vm->id : $vm);
    }

    public function scopeWithStatus(Builder $query, TerminalSessionStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof TerminalSessionStatus ? $status->value : $status);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('started_at');
    }

    public function scopeStale(Builder $query, int $idleMinutes): Builder
    {
        return $query
            ->active()
            ->where('last_activity_at', '<', now()->subMinutes($idleMinutes));
    }

    public function isActive(): bool
    {
        return $this->status === TerminalSessionStatus::Active && $this->ended_at === null;
    }

    public function isPending(): bool
    {
        return $this->status === TerminalSessionStatus::Pending && $this->ended_at === null;
    }

    public function isEnded(): bool
    {
        // Semua status terminal final diperlakukan sama: tidak boleh menerima command baru.
        return $this->ended_at !== null
            || in_array($this->status, [
                TerminalSessionStatus::Closed,
                TerminalSessionStatus::Expired,
                TerminalSessionStatus::Revoked,
            ], true);
    }

    public function isExpired(): bool
    {
        return $this->status === TerminalSessionStatus::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function expireIfPastDue(?Carbon $at = null): bool
    {
        $now = $at ?? now();

        if ($this->isEnded() || $this->expires_at === null || $this->expires_at->isFuture()) {
            return false;
        }

        // Expire pasif menjaga sesi lama tertutup walau tidak ada scheduler khusus yang berjalan.
        return $this->expire($now);
    }

    public function isOwnedBy(User|int $user): bool
    {
        return $this->user_id === ($user instanceof User ? $user->id : $user);
    }

    public function canBeAccessedBy(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'guru') {
            // TODO: Batasi ke siswa bimbingan setelah relasi guru-siswa tersedia.
            return true;
        }

        // Siswa hanya boleh melihat sesi miliknya pada VM miliknya sendiri.
        return in_array($user->role, ['student', 'mahasiswa'], true)
            && $this->isOwnedBy($user)
            && $this->vm?->user_id === $user->id;
    }

    public function touchActivity(?Carbon $at = null): bool
    {
        return $this->forceFill([
            'last_activity_at' => $at ?? now(),
        ])->save();
    }

    public function close(?Carbon $endedAt = null): bool
    {
        return $this->finish(TerminalSessionStatus::Closed, $endedAt);
    }

    public function expire(?Carbon $endedAt = null): bool
    {
        return $this->finish(TerminalSessionStatus::Expired, $endedAt);
    }

    public function revoke(?Carbon $endedAt = null): bool
    {
        return $this->finish(TerminalSessionStatus::Revoked, $endedAt);
    }

    private function finish(TerminalSessionStatus $status, ?Carbon $endedAt = null): bool
    {
        // Satu jalur finalisasi menjaga closed/expired/revoked konsisten untuk audit dan dashboard SOC.
        return $this->forceFill([
            'status' => $status,
            'ended_at' => $endedAt ?? now(),
        ])->save();
    }
}
