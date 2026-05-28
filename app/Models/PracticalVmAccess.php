<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticalVmAccess extends Model
{
    protected $fillable = [
        'vm_id',
        'user_id',
        'assigned_by',
    ];

    public function vm(): BelongsTo
    {
        return $this->belongsTo(Vm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
