<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LabTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'proxmox_template_id',
        'node',
        'cpu_cores',
        'memory_mb',
        'disk_gb',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function vms(): HasMany
    {
        return $this->hasMany(Vm::class);
    }
}
