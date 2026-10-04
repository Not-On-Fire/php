<?php

use NotOnFire\TestException;

test('the test event goes through the exception handler the way a real error does', function () {
    $this->artisan('notonfire:test')
        ->expectsOutputToContain('errors.example.test, project 42')
        ->expectsOutputToContain('Test event sent through the exception handler')
        ->assertSuccessful();

    expect($this->transport->events)->toHaveCount(1)
        ->and($this->transport->events[0]->getExceptions()[0]->getType())->toBe(TestException::class);
});

test('the DSN key is never printed', function () {
    $this->artisan('notonfire:test')
        ->doesntExpectOutputToContain('public-key')
        ->assertSuccessful();
});

describe('in the local environment', function () {
    beforeEach(function () {
        $this->boot('local');
    });

    test('nothing is sent without --force', function () {
        $this->artisan('notonfire:test')
            ->expectsOutputToContain('nothing is sent from local')
            ->expectsOutputToContain('Run again with --force')
            ->assertSuccessful();

        expect($this->transport->events)->toBe([]);
    });

    test('--force sends one anyway', function () {
        $this->artisan('notonfire:test', ['--force' => true])
            ->expectsOutputToContain('Test event sent with --force')
            ->assertSuccessful();

        expect($this->transport->events)->toHaveCount(1)
            ->and($this->transport->events[0]->getEnvironment())->toBe('local');
    });
});

describe('without a DSN', function () {
    beforeEach(function () {
        $this->boot(configuration: ['notonfire.dsn' => null]);
    });

    test('the command says what is missing', function () {
        $this->artisan('notonfire:test')
            ->expectsOutputToContain('NOTONFIRE_DSN is not set')
            ->assertSuccessful();
    });
});
