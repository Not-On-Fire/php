<?php

use NotOnFire\BeforeSend;
use NotOnFire\Cli;
use NotOnFire\NotOnFire;
use NotOnFire\Tests\FakeTransport;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\UserDataBag;

$variables = ['NOTONFIRE_DSN', 'NOTONFIRE_ENVIRONMENT', 'NOTONFIRE_ENABLED', 'NOTONFIRE_ANALYTICS_ID', 'NOTONFIRE_ANALYTICS_DOMAINS', 'NOTONFIRE_ANALYTICS_URL'];

afterEach(function () use ($variables) {
    foreach ($variables as $variable) {
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
    }

    SentrySdk::setCurrentHub(new Hub);
    BeforeSend::chain(null);
});

test('init configures the client strictly whatever it is passed', function () {
    $transport = new FakeTransport;

    $initialized = NotOnFire::init([
        'dsn' => 'https://public-key@errors.example.test/42',
        'transport' => $transport,
        // The error and exception listeners would stay installed for every
        // later test.
        'default_integrations' => false,
        'traces_sample_rate' => 1.0,
        'enable_tracing' => true,
        'send_default_pii' => true,
        'max_breadcrumbs' => 100,
    ]);

    $options = SentrySdk::getCurrentHub()->getClient()->getOptions();
    expect($initialized)->toBeTrue()
        ->and($options->getTracesSampleRate())->toBeNull()
        ->and($options->shouldSendDefaultPii())->toBeFalse()
        ->and($options->getMaxBreadcrumbs())->toBe(0)
        ->and($options->getEnvironment())->toBe('production');
});

test('init reads the DSN and environment from the environment', function () {
    putenv('NOTONFIRE_DSN=https://public-key@errors.example.test/42');
    putenv('NOTONFIRE_ENVIRONMENT=staging');

    expect(NotOnFire::init(['default_integrations' => false]))->toBeTrue()
        ->and(SentrySdk::getCurrentHub()->getClient()->getOptions()->getEnvironment())->toBe('staging');
});

test('init does nothing without a DSN, locally, under tests or when switched off', function (array $environment) {
    foreach ($environment as $name => $value) {
        putenv($name.'='.$value);
    }

    expect(NotOnFire::init())->toBeFalse()
        ->and(SentrySdk::getCurrentHub()->getClient())->toBeNull();
})->with([
    'no DSN' => [[]],
    'local' => [['NOTONFIRE_DSN' => 'https://k@errors.example.test/1', 'NOTONFIRE_ENVIRONMENT' => 'local']],
    'testing' => [['NOTONFIRE_DSN' => 'https://k@errors.example.test/1', 'NOTONFIRE_ENVIRONMENT' => 'testing']],
    'switched off' => [['NOTONFIRE_DSN' => 'https://k@errors.example.test/1', 'NOTONFIRE_ENABLED' => 'false']],
]);

test('the analytics tag carries the privacy attributes and escapes what it is given', function () {
    $tag = NotOnFire::analyticsScript(['id' => 'site-id', 'domains' => 'a.test" onload="x', 'url' => 'https://analytics.example.test/']);

    expect($tag)->toBe('<script defer src="https://analytics.example.test/script.js" data-website-id="site-id" data-domains="a.test&quot; onload=&quot;x" data-do-not-track="true" data-exclude-search="true" data-exclude-hash="true" data-performance="true" referrerpolicy="no-referrer"></script>');
});

test('the analytics tag defaults to the NotOnFire analytics host and the environment variables', function () {
    putenv('NOTONFIRE_ANALYTICS_ID=site-id');

    expect(NotOnFire::analyticsScript())->toContain('src="https://analytics.notonfire.systems/script.js" data-website-id="site-id" data-do-not-track');
});

test('no analytics tag without an ID or locally', function () {
    expect(NotOnFire::analyticsScript())->toBe('')
        ->and(NotOnFire::analyticsScript(['id' => 'site-id', 'environment' => 'local']))->toBe('');
});

test('before_send keeps the exception and drops the request, the user and the rest', function () {
    $event = Event::createEvent()
        ->setRequest(['url' => 'https://shop.example.test/orders/42'])
        ->setUser(UserDataBag::createFromUserIpAddress('203.0.113.7'))
        ->setTags(['route' => 'orders.show'])
        ->setExtra(['order' => 42])
        ->setTransaction('/orders/{order}')
        ->setServerName('web-1');

    $scrubbed = BeforeSend::handle($event, EventHint::fromArray(['exception' => new RuntimeException('boom')]));

    expect($scrubbed->getRequest())->toBe([])
        ->and($scrubbed->getUser())->toBeNull()
        ->and($scrubbed->getTags())->toBe([])
        ->and($scrubbed->getExtra())->toBe([])
        ->and($scrubbed->getTransaction())->toBeNull()
        ->and($scrubbed->getServerName())->toBeNull();
});

test('before_send lets an exception through once', function () {
    $exception = new RuntimeException('boom');

    $first = BeforeSend::handle(Event::createEvent(), EventHint::fromArray(['exception' => $exception]));
    $second = BeforeSend::handle(Event::createEvent(), EventHint::fromArray(['exception' => $exception]));
    $other = BeforeSend::handle(Event::createEvent(), EventHint::fromArray(['exception' => new RuntimeException('boom')]));

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($other)->not->toBeNull();
});

test('the test script reports what it found and sends nothing from local', function () {
    putenv('NOTONFIRE_DSN=https://public-key@errors.example.test/42');
    putenv('NOTONFIRE_ENVIRONMENT=local');
    putenv('NOTONFIRE_ANALYTICS_ID=site-id');
    $output = fopen('php://memory', 'w+');

    $status = Cli::run([], $output);

    rewind($output);
    $printed = stream_get_contents($output);
    expect($status)->toBe(0)
        ->and($printed)->toContain('DSN set for errors.example.test, project 42')
        ->toContain('nothing is sent from local')
        ->toContain('Run again with --force')
        ->toContain('data-website-id="site-id"')
        ->not->toContain('public-key');
});
