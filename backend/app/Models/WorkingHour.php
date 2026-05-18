<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingHour extends Model
{
    protected $table = 'working_hours';

    protected $fillable = [
        'user_id',
        'master_id',
        'weekday',
        'opens_at',
        'closes_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }
}
