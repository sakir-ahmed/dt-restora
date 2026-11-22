<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Posts a readable alert to a Telegram chat for each warning, error or worse.
 *
 * It must never break the request it is reporting on: every failure inside it is swallowed,
 * and the same problem is sent once per throttle window with a count of the repeats.
 */
class TelegramHandler extends AbstractProcessingHandler
{
    private const LIMIT = 3900;

    private static bool $sending = false;

    public function __construct(Level $level = Level::Warning)
    {
        parent::__construct($level, true);
    }

    protected function write(LogRecord $record): void
    {
        $token = (string) config('services.telegram.bot_token');
        $chat = (string) config('services.telegram.chat_id');

        if (self::$sending || ! config('services.telegram.notify_errors') || $token === '' || $chat === '') {
            return;
        }

        self::$sending = true;

        try {
            $throwable = $record->context['exception'] ?? null;
            $throwable = $throwable instanceof Throwable ? $throwable : null;

            $repeats = $this->repeatsSince($record, $throwable);

            if ($repeats === null) {
                return;
            }

            Http::asForm()
                ->connectTimeout(2)
                ->timeout(4)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chat,
                    'text' => $this->format($record, $throwable, $repeats),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable) {
            // Reporting an error must never cause another one.
        } finally {
            self::$sending = false;
        }
    }

    /**
     * Returns null when this exact problem was already sent inside the throttle window,
     * otherwise how many identical ones were held back since the last message.
     */
    private function repeatsSince(LogRecord $record, ?Throwable $throwable): ?int
    {
        $window = (int) config('services.telegram.throttle_seconds', 300);

        if ($window <= 0) {
            return 0;
        }

        $signature = $throwable
            ? $throwable::class.'|'.$throwable->getFile().'|'.$throwable->getLine()
            : $record->level->getName().'|'.$record->message;

        $key = 'telegram-alert:'.md5($signature);

        try {
            if (Cache::add($key, 1, $window)) {
                $held = (int) Cache::pull($key.':held', 0);

                return $held;
            }

            Cache::add($key.':held', 0, $window * 2);
            Cache::increment($key.':held');

            return null;
        } catch (Throwable) {
            return 0;
        }
    }

    private function format(LogRecord $record, ?Throwable $throwable, int $repeats): string
    {
        [$icon, $label, $meaning] = $this->severity($record->level);

        $lines = [
            "{$icon} <b>{$label}</b> in <b>".$this->e((string) config('app.name')).'</b>',
            '<i>'.$this->e($meaning).'</i>',
            '',
            '🏷 <b>App:</b> '.$this->e((string) config('app.name')).' ('.$this->e((string) config('app.env')).')',
            '🖥 <b>Server:</b> '.$this->e((string) gethostname()),
            '🔗 <b>URL:</b> '.$this->e((string) config('app.url')),
            '🕒 <b>Time:</b> '.$this->e($record->datetime->setTimezone(new \DateTimeZone((string) config('app.timezone', 'UTC')))->format('d M Y, h:i:s A T')),
        ];

        foreach ($this->origin() as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '💬 <b>Message:</b>';
        $lines[] = '<pre>'.$this->e($this->cut($record->message, 700)).'</pre>';

        if ($throwable !== null) {
            $lines[] = '🧩 <b>Problem type:</b> '.$this->e($this->describe($throwable));
            $lines[] = '📌 <b>Exception:</b> '.$this->e($throwable::class);
            $lines[] = '📄 <b>Where:</b> '.$this->e($this->relative($throwable->getFile()).':'.$throwable->getLine());

            if ($throwable->getMessage() !== '' && $throwable->getMessage() !== $record->message) {
                $lines[] = '<pre>'.$this->e($this->cut($throwable->getMessage(), 700)).'</pre>';
            }

            $frames = $this->appFrames($throwable);

            if ($frames !== []) {
                $lines[] = '🧵 <b>Call path (app code):</b>';
                $lines[] = '<pre>'.$this->e(implode("\n", $frames)).'</pre>';
            }

            if ($throwable->getPrevious() !== null) {
                $lines[] = '↩️ <b>Caused by:</b> '.$this->e($this->cut($throwable->getPrevious()::class.': '.$throwable->getPrevious()->getMessage(), 300));
            }
        }

        $context = collect($record->context)->except('exception')->all();

        if ($context !== []) {
            $lines[] = '🧾 <b>Details:</b>';
            $lines[] = '<pre>'.$this->e($this->cut((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 600)).'</pre>';
        }

        if ($repeats > 0) {
            $lines[] = '🔁 Same problem happened <b>'.$repeats.'</b> more time(s) since the last alert.';
        }

        return $this->cut(implode("\n", $lines), self::LIMIT);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function severity(Level $level): array
    {
        return match (true) {
            $level->value >= Level::Emergency->value => ['🆘', 'EMERGENCY', 'The system is unusable. Act immediately.'],
            $level->value >= Level::Alert->value => ['🚨', 'ALERT', 'Action is needed right now.'],
            $level->value >= Level::Critical->value => ['🔥', 'CRITICAL ERROR', 'A core part of the app is failing.'],
            $level->value >= Level::Error->value => ['❌', 'ERROR', 'Something failed and a user may have seen an error page or a failed action.'],
            default => ['⚠️', 'WARNING', 'Not a failure yet, but it needs a look before it becomes one.'],
        };
    }

    /**
     * Who and what triggered it: web request, API call, console command or queue job.
     *
     * @return array<int, string>
     */
    private function origin(): array
    {
        $app = app();

        if ($app->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            $command = implode(' ', array_slice(array_map('strval', $argv), 1, 6));

            return ['⌨️ <b>Source:</b> console / queue / scheduler', '▶️ <b>Command:</b> '.$this->e($command !== '' ? $command : '-')];
        }

        try {
            $request = request();
        } catch (Throwable) {
            return [];
        }

        $lines = [
            '🌐 <b>Request:</b> '.$this->e($request->method().' '.$this->cut($request->fullUrl(), 250)),
            '📍 <b>IP:</b> '.$this->e((string) $request->ip()),
        ];

        try {
            $user = $request->user('staff') ?? $request->user();

            if ($user !== null) {
                $lines[] = '👤 <b>User:</b> '.$this->e(trim(($user->name ?? '').' #'.$user->getAuthIdentifier()));
            } else {
                $lines[] = '👤 <b>User:</b> guest';
            }
        } catch (Throwable) {
            // Auth may be unavailable when the failure is in the session or database itself.
        }

        $agent = (string) $request->userAgent();

        if ($agent !== '') {
            $lines[] = '📱 <b>Client:</b> '.$this->e($this->cut($agent, 120));
        }

        return $lines;
    }

    private function describe(Throwable $e): string
    {
        return match (true) {
            $e instanceof QueryException => 'Database query failed (constraint, missing table or column, or the database is down)',
            $e instanceof \Illuminate\Http\Client\ConnectionException => 'An outside service could not be reached (network or timeout)',
            $e instanceof \Illuminate\Http\Client\RequestException => 'An outside service answered with an error',
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => 'HTTP error '.$e->getStatusCode(),
            $e instanceof \ErrorException => 'PHP warning or notice turned into an error',
            $e instanceof \TypeError, $e instanceof \Error => 'Programming error in the code (null value, wrong type or missing method)',
            $e instanceof \RuntimeException => 'Runtime failure',
            default => 'Unexpected exception',
        };
    }

    /**
     * @return array<int, string>
     */
    private function appFrames(Throwable $e): array
    {
        $frames = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, '/vendor/')) {
                continue;
            }

            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $frames[] = $this->relative($file).':'.($frame['line'] ?? '?').'  '.$call.'()';

            if (count($frames) >= 6) {
                break;
            }
        }

        return $frames;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 3).'...' : $text;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
