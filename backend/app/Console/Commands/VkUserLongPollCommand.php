<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VkUserLongPollCommand extends Command
{
    protected $signature = 'smartbooking:vk-user-long-poll';

    protected $description = 'Заглушка: Long Poll личных сообщений (подключите токен пользователя и реализацию при расширении MVP)';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
