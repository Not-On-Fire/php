<?php

namespace NotOnFire\Laravel;

use Illuminate\Console\Command;
use NotOnFire\Diagnostics;
use NotOnFire\NotOnFire;
use NotOnFire\TestException;
use Sentry\SentrySdk;

class TestCommand extends Command
{
    protected $signature = 'notonfire:test {--force : Send a test event even from local or testing, or with NOTONFIRE_ENABLED=false}';

    protected $description = 'Show what NotOnFire found and send a test event';

    public function handle(): int
    {
        $dsn = Settings::dsn();
        $analyticsId = Settings::analyticsId();
        $placement = config('notonfire.analytics.inject')
            ? 'added to every HTML page of the web group'
            : 'rendered where the layout uses @notonfireAnalytics';

        $this->components->info('NotOnFire');

        foreach (Diagnostics::rows($dsn, Settings::environment(), Settings::enabled(), $analyticsId, Settings::analyticsDomains(), $placement) as [$label, $value]) {
            $this->components->twoColumnDetail($label, $value);
        }

        $this->newLine();
        $status = self::SUCCESS;

        if ($dsn === null) {
            $this->components->warn('No test event sent: NOTONFIRE_DSN is not set.');
        } elseif (Settings::sends()) {
            $status = $this->report(
                $this->sendThroughExceptionHandler(),
                'through the exception handler, the way a real error goes',
            );
        } elseif ($this->option('force')) {
            $status = $this->report(
                Diagnostics::sendTestEvent($dsn, Settings::environment(), $this->transportOptions()),
                'with --force',
            );
        } else {
            $this->components->warn('No test event sent from here. Run again with --force to send one anyway.');
        }

        if ($analyticsId !== null) {
            $this->newLine();
            $this->line('  Analytics tag:');
            $this->line('  '.NotOnFire::analyticsTag($analyticsId, Settings::analyticsDomains(), Settings::analyticsUrl()));
        }

        return $status;
    }

    /**
     * Reports a TestException the way the application reports any other, so
     * a passing test proves the exception handler hook as well as the DSN.
     */
    private function sendThroughExceptionHandler(): ?string
    {
        $hub = SentrySdk::getCurrentHub();
        $before = $hub->getLastEventId();

        report(new TestException);
        $hub->getClient()?->flush();

        $after = $hub->getLastEventId();

        return $after === null || $after == $before ? null : (string) $after;
    }

    /**
     * How the application's own client reaches the network, so a forced
     * test goes out through the same proxy and timeouts.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(): array
    {
        return array_intersect_key((array) config('sentry', []), array_flip([
            'transport', 'http_client', 'http_proxy', 'http_proxy_authentication',
            'http_timeout', 'http_connect_timeout', 'http_ssl_verify_peer', 'http_compression',
        ]));
    }

    private function report(?string $eventId, string $how): int
    {
        if ($eventId === null) {
            $this->components->error('The test event was not accepted. Check NOTONFIRE_DSN and that this server can reach it.');

            return self::FAILURE;
        }

        $this->components->info("Test event sent {$how} ({$eventId}). It shows up in the NotOnFire dashboard within a minute.");

        return self::SUCCESS;
    }
}
