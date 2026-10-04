<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new RuntimeException('The application configuration is cached. Run `php artisan config:clear` before running tests.');
        }

        return $app;
    }
}
