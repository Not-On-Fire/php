<?php

namespace NotOnFire\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use NotOnFire\Laravel\NotOnFireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\SentrySdk;
use Sentry\State\Hub;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    /**
     * The environment the application runs in. Production by default,
     * because local and testing never send.
     */
    protected string $environment = 'production';

    /** @var array<string, mixed> */
    protected array $configuration = [];

    protected bool $sentryRegistersFirst = false;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;

        parent::setUp();
    }

    /**
     * Builds the application again in another environment or with other
     * configuration. Both are read while the providers register, which is
     * before a test's own beforeEach runs.
     *
     * @param  array<string, mixed>  $configuration
     */
    protected function boot(string $environment = 'production', array $configuration = [], bool $sentryRegistersFirst = false): void
    {
        $this->environment = $environment;
        $this->configuration = $configuration;
        $this->sentryRegistersFirst = $sentryRegistersFirst;
        $this->transport = new FakeTransport;

        Facade::clearResolvedInstances();
        $this->refreshApplication();
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    /**
     * In the order package discovery registers them, notonfire/php sorting
     * before sentry/sentry-laravel, unless a test turns it around.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [NotOnFireServiceProvider::class, SentryServiceProvider::class];

        return $this->sentryRegistersFirst ? array_reverse($providers) : $providers;
    }

    /**
     * Configures the application before the package providers register,
     * which is where both the environment and the configuration are read.
     * Testbench's defineEnvironment() runs only after that.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['env'] = $this->environment;

        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'notonfire.dsn' => 'https://public-key@errors.example.test/42',
            'sentry.transport' => $this->transport,
            ...$this->configuration,
        ]);
    }
}
