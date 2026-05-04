<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'dialog_id',
        'dialog_session_id',
        'direction',
        'text',
    ];

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function dialogSession(): BelongsTo
    {
        return $this->belongsTo(DialogSession::class);
    }
}
