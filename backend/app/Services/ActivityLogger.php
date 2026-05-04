<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    public function log(User $user, string $type, string $summary, ?array $meta = null): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'summary' => $summary,
            'meta' => $meta,
        ]);
    }
}
