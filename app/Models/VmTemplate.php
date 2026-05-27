<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VmTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'proxmox_template_id',
        'proxmox_node',
        'cpu',
        'ram',
        'disk',
        'ssh_username',
        'ssh_password',
        'enabled',
    ];

    protected $hidden = [
        'ssh_password',
    ];

    protected function casts(): array
    {
        return [
            'proxmox_template_id' => 'integer',
            'cpu' => 'integer',
            'ram' => 'integer',
            'disk' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function vms(): HasMany
    {
        return $this->hasMany(Vm::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function sshMetadata(): array
    {
        $metadata = [
            'ssh_username' => $this->ssh_username ?: 'student',
        ];

        if (is_string($this->ssh_password) && $this->ssh_password !== '') {
            $metadata['ssh_password'] = $this->ssh_password;
        }

        return $metadata;
    }
}
