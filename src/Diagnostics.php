<?php

namespace NotOnFire;

use Sentry\ClientBuilder;
use Sentry\State\Hub;

/**
 * What `notonfire:test` and `vendor/bin/notonfire-test` report: the settings
 * the package found, whether it sends from here, and a test event.
 */
final class Diagnostics
{
    /**
     * @return list<array{0:string,1:string}>
     */
    public static function rows(?string $dsn, string $environment, bool $enabled, ?string $analyticsId, ?string $analyticsDomains, string $analyticsPlacement): array
    {
        return [
            ['Error tracking', $dsn === null ? 'no DSN set (NOTONFIRE_DSN)' : 'DSN set for '.self::describeDsn($dsn)],
            ['Environment', $environment],
            ['Sends from here', self::sendingState($dsn, $environment, $enabled, $analyticsId)],
            ['Analytics', $analyticsId === null ? 'no ID set (NOTONFIRE_ANALYTICS_ID)' : $analyticsId.' on '.($analyticsDomains ?? 'any domain')],
            ['Analytics tag', $analyticsId === null ? '-' : $analyticsPlacement],
        ];
    }

    /**
     * The host and project of a DSN, without the key in front of them.
     */
    public static function describeDsn(string $dsn): string
    {
        $parts = parse_url($dsn);

        if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
            return 'an unreadable DSN';
        }

        return $parts['host'].', project '.trim(basename($parts['path']), '/');
    }

    /**
     * Sends one TestException through a client of its own, configured
     * exactly like the real one. Returns the event ID, or null when the event
     * was not accepted.
     *
     * @param  array<string, mixed>  $transportOptions  How to reach the network: proxy, timeouts, transport.
     */
    public static function sendTestEvent(string $dsn, string $environment, array $transportOptions = []): ?string
    {
        $client = ClientBuilder::create(NotOnFire::clientOptions($dsn, $environment, $transportOptions))->getClient();
        $eventId = (new Hub($client))->captureException(new TestException);
        $client->flush();

        return $eventId === null ? null : (string) $eventId;
    }

    private static function sendingState(?string $dsn, string $environment, bool $enabled, ?string $analyticsId): string
    {
        if (! $enabled) {
            return 'no - NOTONFIRE_ENABLED is false';
        }

        if (NotOnFire::isSilent($environment)) {
            return 'no - nothing is sent from '.$environment.' (use --force to send a test event anyway)';
        }

        if ($dsn === null && $analyticsId === null) {
            return 'no - nothing is configured';
        }

        return 'yes';
    }
}
