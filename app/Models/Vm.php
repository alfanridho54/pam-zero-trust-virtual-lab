<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vm extends Model
{
    use SoftDeletes;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labTemplate(): BelongsTo
    {
        return $this->belongsTo(LabTemplate::class);
    }
}
