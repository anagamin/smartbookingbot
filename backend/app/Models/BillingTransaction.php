<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingTransaction extends Model
{
    public const TYPE_TOPUP = 'topup';

    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_PLAN_PURCHASE = 'plan_purchase';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'user_id',
        'amount_kopecks',
        'type',
        'external_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
