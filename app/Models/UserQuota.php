<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class UserQuota extends Model
{
    protected $fillable = [
        'user_id',
        'max_vms',
        'max_cpu_cores',
        'max_memory_mb',
        'max_disk_gb',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
