<?php

namespace NotOnFire\Laravel;

use NotOnFire\NotOnFire;

/**
 * The package's settings as the Laravel application has them.
 */
final class Settings
{
    public static function dsn(): ?string
    {
        return self::string('notonfire.dsn');
    }

    public static function environment(): string
    {
        return (string) app()->environment();
    }

    public static function enabled(): bool
    {
        return (bool) config('notonfire.enabled', true);
    }

    /**
     * Whether events and the analytics tag leave this application at all:
     * switched on, and not running locally or under tests.
     */
    public static function active(): bool
    {
        return self::enabled() && ! NotOnFire::isSilent(self::environment());
    }

    public static function sends(): bool
    {
        return self::active() && self::dsn() !== null;
    }

    public static function analyticsId(): ?string
    {
        return self::string('notonfire.analytics.id');
    }

    public static function analyticsDomains(): ?string
    {
        return self::string('notonfire.analytics.domains');
    }

    public static function analyticsUrl(): string
    {
        return self::string('notonfire.analytics.url') ?? NotOnFire::DEFAULT_ANALYTICS_URL;
    }

    private static function string(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
