<?php

namespace App\Console\Commands;

use App\Models\DialogSession;
use Illuminate\Console\Command;

class CloseDialogSessionsCommand extends Command
{
    protected $signature = 'smartbooking:close-dialog-sessions';

    protected $description = 'Закрыть диалог-сессии без активности более 24 часов';

    public function handle(): int
    {
        $threshold = now()->subDay();

        DialogSession::query()
            ->where('status', DialogSession::STATUS_OPEN)
            ->where('started_at', '<', $threshold)
            ->whereDoesntHave('messages', fn ($q) => $q->where('created_at', '>', $threshold))
            ->update([
                'status' => DialogSession::STATUS_CLOSED,
                'closed_at' => now(),
            ]);

        return self::SUCCESS;
    }
}
