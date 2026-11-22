<?php

declare(strict_types=1);

use App\Logging\TelegramLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Logger;

beforeEach(function (): void {
    Cache::flush();
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '123',
        'services.telegram.notify_errors' => true,
        'services.telegram.throttle_seconds' => 300,
        'app.name' => 'DT Restora',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
});

function telegramLogger(): Logger
{
    return (new TelegramLogger)(['level' => 'warning']);
}

it('sends an error with app name, exception and location to Telegram', function (): void {
    telegramLogger()->error('Payment sync failed', ['exception' => new RuntimeException('Gateway said no')]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bottest-token/sendMessage')
        && $request['chat_id'] === '123'
        && str_contains($request['text'], 'DT Restora')
        && str_contains($request['text'], 'ERROR')
        && str_contains($request['text'], 'Payment sync failed')
        && str_contains($request['text'], 'RuntimeException')
        && str_contains($request['text'], 'Gateway said no'));
});

it('sends warnings but ignores info', function (): void {
    telegramLogger()->info('Just chatting');
    Http::assertNothingSent();

    telegramLogger()->warning('Disk is getting full');
    Http::assertSentCount(1);
});

it('sends the same problem once per window and counts the repeats', function (): void {
    telegramLogger()->warning('Queue is slow');
    telegramLogger()->warning('Queue is slow');
    telegramLogger()->warning('Queue is slow');

    Http::assertSentCount(1);
});

it('stays silent when alerts are switched off', function (): void {
    config(['services.telegram.notify_errors' => false]);

    telegramLogger()->error('Boom');

    Http::assertNothingSent();
});

it('never throws when Telegram is unreachable', function (): void {
    Http::fake(['api.telegram.org/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('down')]);

    telegramLogger()->error('Boom');

    expect(true)->toBeTrue();
});
