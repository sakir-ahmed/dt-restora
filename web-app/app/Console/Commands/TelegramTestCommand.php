<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramTestCommand extends Command
{
    protected $signature = 'telegram:test';

    protected $description = 'Send a sample warning and a sample error to the Telegram alert chat';

    public function handle(): int
    {
        if (! config('services.telegram.notify_errors') || ! config('services.telegram.bot_token') || ! config('services.telegram.chat_id')) {
            $this->error('Telegram alerts are off. Set TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID and TELEGRAM_NOTIFY_ERRORS=true.');

            return self::FAILURE;
        }

        config(['services.telegram.throttle_seconds' => 0]);

        Log::channel('telegram')->warning('Test warning from telegram:test. Alerts are working.');
        Log::channel('telegram')->error('Test error from telegram:test.', ['exception' => new \RuntimeException('Sample exception to preview the alert layout')]);

        $this->info('Sent a test warning and a test error. Check the Telegram chat.');

        return self::SUCCESS;
    }
}
