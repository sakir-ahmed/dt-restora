<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Custom log driver: sends every warning and error the application logs to Telegram.
 */
class TelegramLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $level = Level::fromName(ucfirst(strtolower((string) ($config['level'] ?? 'warning'))));

        return new Logger('telegram', [new TelegramHandler($level)]);
    }
}
