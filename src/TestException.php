<?php

namespace NotOnFire;

use RuntimeException;

/**
 * Raised on purpose by `php artisan notonfire:test` and
 * `vendor/bin/notonfire-test` to prove that errors reach NotOnFire.
 */
final class TestException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This is a test event from NotOnFire. Error tracking works.');
    }
}
