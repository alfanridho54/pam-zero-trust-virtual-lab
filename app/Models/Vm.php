<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vm extends Model
{
    use SoftDeletes;

    public const INFRASTRUCTURE_VMIDS = [100, 102];

    protected $fillable = [
        'user_id',
        'lab_template_id',
        'name',
        'proxmox_id',
        'node',
        'status',
        'cpu_cores',
        'memory_mb',
        'disk_gb',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vm $vm): void {
            if (! $vm->hasInfrastructureVmid()) {
                return;
            }

            $vm->metadata = [
                ...($vm->metadata ?? []),
                'system_vm' => true,
                'critical' => true,
            ];
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labTemplate(): BelongsTo
    {
        return $this->belongsTo(LabTemplate::class);
    }

    public function terminalSessions(): HasMany
    {
        return $this->hasMany(TerminalSession::class);
    }

    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class);
    }

    public function proxmoxVmid(): ?int
    {
        // VMID dapat berasal dari proxmox_id atau metadata agar record mock dan real tetap bisa dicocokkan.
        if (is_numeric($this->proxmox_id)) {
            return (int) $this->proxmox_id;
        }

        $metadataVmid = $this->metadata['vmid'] ?? null;

        if (is_numeric($metadataVmid)) {
            return (int) $metadataVmid;
        }

        return null;
    }

    public function matchesProxmoxVm(string $node, int $vmid): bool
    {
        // Pencocokan node+VMID adalah dasar isolasi ownership untuk inventory Proxmox nyata.
        return $this->node === $node && $this->proxmoxVmid() === $vmid;
    }

    public function isSystemVm(): bool
    {
        return $this->hasInfrastructureVmid() || $this->metadataFlag('system_vm');
    }

    public function isCriticalVm(): bool
    {
        return $this->hasInfrastructureVmid()
            || $this->proxmoxVmid() === 101
            || $this->metadataFlag('critical')
            || str($this->name)->lower()->contains('jump-server');
    }

    public function isCritical(): bool
    {
        return $this->isCriticalVm();
    }

    public function isProtectedVm(): bool
    {
        return $this->isSystemVm() || $this->isCriticalVm() || $this->metadataFlag('protected');
    }

    public function isStudentVisible(): bool
    {
        return ! $this->trashed() && ! $this->isSystemVm() && ! $this->isCritical();
    }

    public function scopeStudentVisible(Builder $query): Builder
    {
        return $query->whereNull($this->getQualifiedDeletedAtColumn());
    }

    private function metadataFlag(string $key): bool
    {
        return filter_var($this->metadata[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function hasInfrastructureVmid(): bool
    {
        $vmid = $this->proxmoxVmid();

        return $vmid !== null && in_array($vmid, self::INFRASTRUCTURE_VMIDS, true);
    }
}
