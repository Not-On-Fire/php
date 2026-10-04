<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use NotOnFire\BeforeSend;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Sentry\Options;
use Sentry\SentrySdk;

function clientOptions(): Options
{
    return SentrySdk::getCurrentHub()->getClient()->getOptions();
}

describe('a published config/sentry.php', function () {
    beforeEach(function () {
        // What `php artisan sentry:publish` writes when Enter is pressed at
        // "Enable Performance Monitoring?", plus the PII default flipped.
        $this->boot(configuration: [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.profiles_sample_rate' => 1.0,
            'sentry.enable_tracing' => true,
            'sentry.send_default_pii' => true,
            'sentry.enable_logs' => true,
            'sentry.breadcrumbs' => ['sql_queries' => true, 'sql_bindings' => true],
        ]);
    });

    test('cannot loosen the strict configuration', function () {
        $options = clientOptions();

        expect($options->getDsn()?->getHost())->toBe('errors.example.test')
            ->and($options->getTracesSampleRate())->toBeNull()
            ->and($options->getProfilesSampleRate())->toBeNull()
            ->and($options->shouldSendDefaultPii())->toBeFalse()
            ->and($options->getEnableLogs())->toBeFalse()
            ->and($options->getMaxBreadcrumbs())->toBe(0)
            ->and($options->getMaxRequestBodySize())->toBe('none')
            ->and($options->getServerName())->toBe('')
            ->and($options->getEnvironment())->toBe('production');
        expect(config('sentry.breadcrumbs.sql_queries'))->toBeFalse()
            ->and(config('sentry.tracing.default_integrations'))->toBeFalse();
    });
});

test('the strict configuration also wins when sentry-laravel registers first', function () {
    $this->boot(configuration: ['sentry.traces_sample_rate' => 1.0, 'sentry.send_default_pii' => true], sentryRegistersFirst: true);

    expect(clientOptions()->getTracesSampleRate())->toBeNull()
        ->and(clientOptions()->shouldSendDefaultPii())->toBeFalse()
        ->and(clientOptions()->getDsn()?->getHost())->toBe('errors.example.test');
});

test('an unhandled exception is reported once, with the stack trace and nothing about the request', function () {
    Route::middleware('web')->get('/checkout', fn () => throw new RuntimeException('Payment provider timed out'));

    $this->withHeader('Cookie', 'session=secret')->get('/checkout?email=someone@example.test');

    expect($this->transport->events)->toHaveCount(1);
    $event = $this->transport->events[0];
    expect($event->getExceptions()[0]->getType())->toBe(RuntimeException::class)
        ->and($event->getExceptions()[0]->getStacktrace()?->getFrames())->not->toBeEmpty()
        ->and($event->getRequest())->toBe([])
        ->and($event->getUser())->toBeNull()
        ->and($event->getTags())->toBe([])
        ->and($event->getExtra())->toBe([])
        ->and($event->getTransaction())->toBeNull()
        ->and($event->getBreadcrumbs())->toBe([]);
});

test('an Integration::handles call left over from a manual setup does not report twice', function () {
    app(ExceptionHandler::class)->reportable(static function (Throwable $exception): void {
        Integration::captureUnhandledException($exception);
    });
    Route::middleware('web')->get('/checkout', fn () => throw new RuntimeException('Payment provider timed out'));

    $this->get('/checkout');

    expect($this->transport->events)->toHaveCount(1);
});

describe('the application before_send', function () {
    beforeEach(function () {
        $this->boot(configuration: ['sentry.before_send' => [ApplicationBeforeSend::class, 'handle']]);
    });

    test('still runs, and what it adds is scrubbed', function () {
        Route::middleware('web')->get('/checkout', fn () => throw new RuntimeException('Payment provider timed out'));

        $this->get('/checkout');

        expect(ApplicationBeforeSend::$calls)->toBe(1)
            ->and(config('sentry.before_send'))->toBe([BeforeSend::class, 'handle'])
            ->and($this->transport->events[0]->getTags())->toBe([]);
    });
});

final class ApplicationBeforeSend
{
    public static int $calls = 0;

    public static function handle(Event $event): Event
    {
        self::$calls++;

        return $event->setTags(['customer' => 'someone@example.test']);
    }
}

describe('in the local environment', function () {
    beforeEach(function () {
        $this->boot('local');
    });

    test('nothing is sent, even with a DSN set', function () {
        // Without a DSN the SDK's own transport sends nothing. The fake one
        // used here would, so the client's DSN is what is asserted.
        expect(config('sentry.dsn'))->toBeNull()
            ->and(clientOptions()->getDsn())->toBeNull();
    });
});

describe('with NOTONFIRE_ENABLED=false', function () {
    beforeEach(function () {
        $this->boot(configuration: ['notonfire.enabled' => false]);
    });

    test('nothing is sent', function () {
        expect(config('sentry.dsn'))->toBeNull();
    });
});
