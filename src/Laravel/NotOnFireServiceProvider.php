<?php

namespace NotOnFire\Laravel;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use NotOnFire\BeforeSend;
use NotOnFire\NotOnFire;
use ReflectionObject;
use Sentry\Laravel\Integration;
use Throwable;

/**
 * Does the steps of a manual Sentry install for the application, and adds
 * the analytics tag:
 *
 * - configures the SDK from NOTONFIRE_DSN, strictly, over config/sentry.php
 *   and any SENTRY_* variable;
 * - hands unhandled exceptions to it, which on Laravel 11 and up otherwise
 *   takes an Integration::handles() call in bootstrap/app.php;
 * - prints the analytics tag into every HTML page of the web group.
 */
class NotOnFireServiceProvider extends ServiceProvider
{
    /**
     * Every breadcrumb sentry-laravel records, all switched off. One added in
     * a later release is still dropped, because `max_breadcrumbs` is 0.
     */
    private const BREADCRUMBS = [
        'logs', 'cache', 'livewire', 'sql_queries', 'sql_bindings', 'queue_info',
        'command_info', 'http_client_requests', 'notifications',
    ];

    /**
     * Every tracing switch sentry-laravel reads. With no sample rate nothing
     * is traced anyway; these keep the integrations from being set up at all.
     */
    private const TRACING = [
        'queue_job_transactions', 'queue_jobs', 'sql_queries', 'sql_bindings', 'sql_origin',
        'views', 'livewire', 'http_client_requests', 'cache', 'redis_commands', 'redis_origin',
        'notifications', 'missing_routes', 'continue_after_response', 'gen_ai',
        'gen_ai_invoke_agent', 'gen_ai_chat', 'gen_ai_execute_tool', 'gen_ai_embeddings',
        'default_integrations',
    ];

    /**
     * A `before_send` the application configured itself. It still runs,
     * before the package's own, so nothing it adds survives the scrubbing.
     *
     * @var callable|null
     */
    private $chainedBeforeSend = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/notonfire.php', 'notonfire');

        // Before any provider boots, which is when sentry-laravel builds its
        // client from this configuration. Its own mergeConfigFrom keeps keys
        // that already exist, so the order of the two providers does not
        // matter.
        $this->enforceSentryConfiguration();
    }

    public function boot(): void
    {
        BeforeSend::chain($this->chainedBeforeSend ?? $this->cachedChainedBeforeSend());

        $this->reportUnhandledExceptions();

        if ($this->app['config']->get('notonfire.analytics.inject')) {
            $this->injectAnalyticsIntoWebGroup();
        }

        Blade::directive('notonfireAnalytics', fn (): string => '<?php echo \\'.AnalyticsTag::class.'::render(); ?>');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
            $this->publishes([__DIR__.'/../../config/notonfire.php' => config_path('notonfire.php')], 'notonfire-config');
        }
    }

    /**
     * Hooks the application's own exception handler, the one
     * Integration::handles() would be given in bootstrap/app.php.
     *
     * Bound to the concrete Handler class, like Laravel's withExceptions():
     * in the console, Collision wraps the handler in an adapter that has no
     * reportable(), and a hook on the contract would land on the adapter. If
     * the handler was already resolved, the adapter is looked through.
     */
    private function reportUnhandledExceptions(): void
    {
        $hook = static function (object $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(static function (Throwable $exception): void {
                    Integration::captureUnhandledException($exception);
                });
            }
        };

        if ($this->app->resolved(ExceptionHandler::class)) {
            $hook(self::unwrap($this->app->make(ExceptionHandler::class)));

            return;
        }

        $this->app->afterResolving(Handler::class, $hook);
    }

    /**
     * The handler a decorator such as Collision's adapter delegates to.
     */
    private static function unwrap(object $handler): object
    {
        if ($handler instanceof Handler) {
            return $handler;
        }

        foreach ((new ReflectionObject($handler))->getProperties() as $property) {
            $inner = $property->isInitialized($handler) ? $property->getValue($handler) : null;

            if ($inner instanceof Handler) {
                return $inner;
            }
        }

        return $handler;
    }

    /**
     * Through the HTTP kernel rather than the router: the kernel copies its
     * own middleware groups over the router's whenever they change, which
     * would silently drop a middleware pushed to the router alone.
     */
    private function injectAnalyticsIntoWebGroup(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if ($kernel instanceof Kernel) {
            $kernel->appendMiddlewareToGroup('web', InjectAnalyticsScript::class);

            return;
        }

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', InjectAnalyticsScript::class);
    }

    private function enforceSentryConfiguration(): void
    {
        $config = $this->app['config'];
        $sentry = (array) $config->get('sentry', []);
        $previousBeforeSend = $sentry['before_send'] ?? null;

        if ($previousBeforeSend !== [BeforeSend::class, 'handle'] && is_callable($previousBeforeSend)) {
            $this->chainedBeforeSend = $previousBeforeSend;

            // Kept in the configuration too, so it survives config:cache.
            // A closure cannot be cached in the first place.
            if (! $previousBeforeSend instanceof Closure) {
                $config->set('notonfire.chained_before_send', $previousBeforeSend);
            }
        }

        unset($sentry['enable_tracing'], $sentry['traces_sampler'], $sentry['profiles_sampler']);

        $config->set('sentry', [
            ...$sentry,
            'dsn' => Settings::sends() ? Settings::dsn() : null,
            'environment' => Settings::environment(),
            'breadcrumbs' => array_fill_keys(self::BREADCRUMBS, false),
            'tracing' => array_fill_keys(self::TRACING, false),
            ...NotOnFire::strictOptions(),
        ]);
    }

    private function cachedChainedBeforeSend(): ?callable
    {
        $chained = $this->app['config']->get('notonfire.chained_before_send');

        return is_callable($chained) ? $chained : null;
    }
}
