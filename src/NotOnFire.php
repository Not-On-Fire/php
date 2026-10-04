<?php

namespace NotOnFire;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * Error tracking and analytics for plain PHP, and the settings the Laravel
 * integration shares with it.
 *
 * Error tracking reports through the Sentry SDK, configured strictly: no
 * tracing, no profiling, no PII, no logs, no metrics, no breadcrumbs, no
 * request bodies and no server name. That configuration is not a default to
 * be adjusted. It is applied over whatever else is passed in.
 */
final class NotOnFire
{
    public const DEFAULT_ANALYTICS_URL = 'https://analytics.notonfire.systems';

    /**
     * Environments nothing is ever sent from, so a DSN copied into a local
     * .env does not report every typo to production's error tracking.
     */
    public const SILENT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Options that could switch tracing back on behind the strict ones.
     */
    private const REJECTED_OPTIONS = ['enable_tracing', 'traces_sampler', 'profiles_sampler'];

    /**
     * Sets up error tracking for a plain PHP application.
     *
     * Call it once, as early as possible: right after vendor/autoload.php.
     * The DSN comes from the argument or from NOTONFIRE_DSN. Without one, in
     * a local or testing environment, or with NOTONFIRE_ENABLED=false, it
     * does nothing.
     *
     * @param  string|array<string, mixed>  $options  A DSN, or Sentry options with `dsn` among them.
     */
    public static function init(string|array $options = []): bool
    {
        $options = is_string($options) ? ['dsn' => $options] : $options;
        $dsn = self::stringOption($options, 'dsn') ?? self::env('NOTONFIRE_DSN');
        $environment = self::stringOption($options, 'environment') ?? self::environment();

        if ($dsn === null || ! self::enabled() || self::isSilent($environment)) {
            return false;
        }

        BeforeSend::chain(is_callable($options['before_send'] ?? null) ? $options['before_send'] : null);

        \Sentry\init(self::clientOptions($dsn, $environment, $options));

        return true;
    }

    /**
     * The analytics tag, or an empty string when there is no analytics ID or
     * nothing may be sent from this environment. Echo it inside <head>.
     *
     * @param  array{id?:string,domains?:string,url?:string,environment?:string,enabled?:bool}  $options
     */
    public static function analyticsScript(array $options = []): string
    {
        $id = self::stringOption($options, 'id') ?? self::env('NOTONFIRE_ANALYTICS_ID');
        $environment = self::stringOption($options, 'environment') ?? self::environment();
        $enabled = $options['enabled'] ?? self::enabled();

        if ($id === null || ! $enabled || self::isSilent($environment)) {
            return '';
        }

        return self::analyticsTag(
            $id,
            self::stringOption($options, 'domains') ?? self::env('NOTONFIRE_ANALYTICS_DOMAINS'),
            self::stringOption($options, 'url') ?? self::env('NOTONFIRE_ANALYTICS_URL') ?? self::DEFAULT_ANALYTICS_URL,
        );
    }

    /**
     * The tag itself, whatever the environment. The attributes are the ones
     * the dashboard's own snippet carries: do-not-track is honoured, query
     * strings and fragments are never recorded, and Web Vitals are measured.
     */
    public static function analyticsTag(string $id, ?string $domains, string $url): string
    {
        $attributes = [
            'src' => rtrim($url, '/').'/script.js',
            'data-website-id' => $id,
            'data-domains' => $domains,
            'data-do-not-track' => 'true',
            'data-exclude-search' => 'true',
            'data-exclude-hash' => 'true',
            'data-performance' => 'true',
            'referrerpolicy' => 'no-referrer',
        ];

        $html = '<script defer';
        foreach ($attributes as $name => $value) {
            if ($value !== null && $value !== '') {
                $html .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
        }

        return $html.'></script>';
    }

    /**
     * The options the package enforces. They are applied after anything the
     * application passes, so none of them can be loosened.
     *
     * @return array<string, mixed>
     */
    public static function strictOptions(): array
    {
        return [
            'traces_sample_rate' => null,
            'profiles_sample_rate' => null,
            'send_default_pii' => false,
            'enable_logs' => false,
            'enable_metrics' => false,
            'max_breadcrumbs' => 0,
            'max_request_body_size' => 'none',
            'server_name' => '',
            'before_send' => [BeforeSend::class, 'handle'],
        ];
    }

    /**
     * Everything the Sentry client is built with: the caller's options, then
     * the DSN and environment, then the strict options on top.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function clientOptions(string $dsn, string $environment, array $options = []): array
    {
        $options = array_diff_key($options, array_flip(self::REJECTED_OPTIONS));
        $vendor = self::vendorDirectory();

        return [
            ...($vendor === null ? [] : ['in_app_exclude' => [$vendor]]),
            ...$options,
            'dsn' => $dsn,
            'environment' => $environment,
            ...self::strictOptions(),
        ];
    }

    public static function enabled(): bool
    {
        $value = self::env('NOTONFIRE_ENABLED');

        return $value === null || ! in_array(strtolower($value), ['false', '0', 'off', 'no', '(false)'], true);
    }

    public static function environment(): string
    {
        return self::env('NOTONFIRE_ENVIRONMENT') ?? 'production';
    }

    public static function isSilent(string $environment): bool
    {
        return in_array(strtolower($environment), self::SILENT_ENVIRONMENTS, true);
    }

    /**
     * Reads a variable from the real environment, or from $_ENV and $_SERVER
     * where a dotenv loader put it.
     */
    public static function env(string $name): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function vendorDirectory(): ?string
    {
        if (! class_exists(ClassLoader::class)) {
            return null;
        }

        $file = (new ReflectionClass(ClassLoader::class))->getFileName();

        return is_string($file) ? dirname($file, 2) : null;
    }
}
