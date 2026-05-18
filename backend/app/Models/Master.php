<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Master extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
