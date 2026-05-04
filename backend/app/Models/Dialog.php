<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dialog extends Model
{
    protected $fillable = [
        'user_id',
        'social_account_id',
        'external_client_id',
        'client_name',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DialogSession::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
