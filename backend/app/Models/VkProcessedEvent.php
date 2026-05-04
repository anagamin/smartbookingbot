<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VkProcessedEvent extends Model
{
    protected $fillable = [
        'vk_group_id',
        'event_id',
    ];
}
