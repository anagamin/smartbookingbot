<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthPkceState extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'state';

    protected $table = 'oauth_pkce_states';

    public $timestamps = false;

    protected $fillable = [
        'state',
        'code_verifier',
        'provider',
        'user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
