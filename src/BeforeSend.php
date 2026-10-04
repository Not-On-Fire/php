<?php

namespace NotOnFire;

use Sentry\Event;
use Sentry\EventHint;
use WeakMap;

/**
 * The last word on every event before it leaves the application.
 *
 * It strips what the strict options already keep out, in case an
 * integration or the application's own `before_send` put it back: the
 * request, the user, extra data, tags, the transaction name, breadcrumbs and
 * the server name. What is left is the exception, its stack trace and the
 * runtime it happened in.
 *
 * It also drops an exception it has already let through. The package hooks
 * into Laravel's exception handler itself, and an `Integration::handles()`
 * left over from a manual setup would otherwise report every exception twice.
 *
 * Registered as an array callable, never a closure, so `config:cache` can
 * still serialize the configuration.
 */
final class BeforeSend
{
    /** @var callable|null */
    private static $next = null;

    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $reported = null;

    /**
     * Runs the application's own `before_send` first, so nothing it adds
     * survives the scrubbing.
     */
    public static function chain(?callable $next): void
    {
        self::$next = $next !== null && $next !== [self::class, 'handle'] ? $next : null;
    }

    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        if ($exception !== null) {
            self::$reported ??= new WeakMap;

            if (isset(self::$reported[$exception])) {
                return null;
            }

            self::$reported[$exception] = true;
        }

        if (self::$next !== null) {
            $event = (self::$next)($event, $hint);

            if (! $event instanceof Event) {
                return null;
            }
        }

        return $event
            ->setRequest([])
            ->setUser(null)
            ->setExtra([])
            ->setTags([])
            ->setTransaction(null)
            ->setBreadcrumb([])
            ->setServerName(null);
    }
}
