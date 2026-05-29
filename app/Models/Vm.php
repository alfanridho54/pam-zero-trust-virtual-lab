<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vm extends Model
{
    use SoftDeletes;

    public const INFRASTRUCTURE_VMIDS = [100, 102];

    protected $fillable = [
        'user_id',
        'lab_template_id',
        'vm_template_id',
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

    public function vmTemplate(): BelongsTo
    {
        return $this->belongsTo(VmTemplate::class);
    }

    public function terminalSessions(): HasMany
    {
        return $this->hasMany(TerminalSession::class);
    }

    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class);
    }

    public function practicalAccesses(): HasMany
    {
        return $this->hasMany(PracticalVmAccess::class);
    }

    public function proxmoxVmid(): ?int
    {
        // VMID dapat berasal dari proxmox_id atau metadata agar record mock dan real tetap bisa dicocokkan.
        if (is_numeric($this->proxmox_id)) {
            return (int) $this->proxmox_id;
        }

        $metadataVmid = $this->sshMetadataArray()['vmid'] ?? null;

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

    public function isManagedAssignment(): bool
    {
        return $this->metadataFlag('managed_assignment');
    }

    public function isSharedPractical(): bool
    {
        return $this->metadataFlag('shared_practical');
    }

    public function hasPracticalAccess(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('practicalAccesses')) {
            return $this->practicalAccesses->contains('user_id', $userId);
        }

        return $this->practicalAccesses()->where('user_id', $userId)->exists();
    }

    public function isUsableManagedTemplate(): bool
    {
        return ! $this->trashed()
            && (! $this->isProtectedVm() || $this->metadataFlag('usable_template') || $this->metadataFlag('managed_template'));
    }

    public function isProvisionedStudentVm(): bool
    {
        $metadata = $this->sshMetadataArray();

        return in_array($metadata['provisioning'] ?? null, ['template-clone', 'student-self-service', 'managed-template-clone'], true)
            || array_key_exists('source_template_vmid', $metadata)
            || array_key_exists('task_upid', $metadata);
    }

    public function sshHost(): ?string
    {
        return $this->getResolvedSshHost();
    }

    public function getResolvedSshHost(): ?string
    {
        $metadata = $this->sshMetadataArray();
        $host = $metadata['ssh_host']
            ?? $metadata['ip_address']
            ?? $this->localSshAttribute('ssh_host')
            ?? $this->localSshAttribute('ip_address')
            ?? $metadata['target_host']
            ?? $metadata['ip']
            ?? $metadata['private_ip']
            ?? $metadata['public_ip']
            ?? $this->localSshAttribute('private_ip')
            ?? $this->localSshAttribute('public_ip')
            ?? null;

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }

    public function sshPort(): int
    {
        $metadata = $this->sshMetadataArray();

        return (int) (
            $this->localSshAttribute('ssh_port')
            ?? $metadata['target_port']
            ?? $metadata['ssh_port']
            ?? config('services.terminal.target_port')
            ?? 22
        );
    }

    public function sshUsername(): string
    {
        return $this->getResolvedSshUsername();
    }

    public function getResolvedSshUsername(): string
    {
        $metadata = $this->sshMetadataArray();

        return (string) (
            $metadata['ssh_username']
            ?? $this->localSshAttribute('ssh_username')
            ?? $metadata['target_username']
            ?? config('services.terminal.target_username')
            ?? 'student'
        );
    }

    public function sshPassword(): ?string
    {
        return $this->getResolvedSshPassword();
    }

    public function getResolvedSshPassword(): ?string
    {
        $metadata = $this->sshMetadataArray();
        $password = $metadata['ssh_password']
            ?? $this->localSshAttribute('ssh_password')
            ?? null;

        return is_string($password) && $password !== '' ? $password : null;
    }

    public function sshPrivateKey(): ?string
    {
        $metadata = $this->sshMetadataArray();
        $privateKey = $this->localSshAttribute('ssh_private_key')
            ?? $metadata['ssh_private_key']
            ?? null;

        return is_string($privateKey) && $privateKey !== '' ? $privateKey : null;
    }

    public function hasSshMetadata(): bool
    {
        return $this->getResolvedSshHost() !== null;
    }

    public function hasResolvedSshCredentials(): bool
    {
        return $this->getResolvedSshHost() !== null
            && $this->getResolvedSshUsername() !== ''
            && ($this->getResolvedSshPassword() !== null || $this->sshPrivateKey() !== null);
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
        return filter_var($this->sshMetadataArray()[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function localSshAttribute(string $key): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->getAttribute($key) : null;
    }

    private function hasInfrastructureVmid(): bool
    {
        $vmid = $this->proxmoxVmid();

        return $vmid !== null && in_array($vmid, self::INFRASTRUCTURE_VMIDS, true);
    }

    private function sshMetadataArray(): array
    {
        return is_array($this->metadata) ? $this->metadata : [];
    }
}
