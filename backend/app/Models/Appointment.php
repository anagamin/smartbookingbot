<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'master_id',
        'service_id',
        'extra_service_ids',
        'dialog_session_id',
        'client_name',
        'starts_at',
        'ends_at',
        'price_kopecks',
        'chat_excerpt',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'extra_service_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function dialogSession(): BelongsTo
    {
        return $this->belongsTo(DialogSession::class);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }
}
