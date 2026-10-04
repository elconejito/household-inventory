<?php

namespace Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Tests\TestCase;

class TestEnvironmentSafetyTest extends TestCase
{
    public function test_phpunit_boots_with_an_isolated_testing_database_configuration(): void
    {
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertSame('testing', $this->app->environment());

        if (config('database.default') === 'sqlite') {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));

            return;
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('household_inventory_test', config('database.connections.mysql.database'));
        $this->assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1']);
        $this->assertContains(config('database.connections.mysql.url'), [null, '']);
    }

    public function test_cached_configuration_is_rejected_and_bootstrap_globals_are_restored(): void
    {
        $cachedConfiguration = $this->app->make('config')->all();
        $originalContainer = Container::getInstance();
        $originalFacadeApplication = Facade::getFacadeApplication();
        $originalEventDispatcher = Model::getEventDispatcher();
        $originalConnectionResolver = Model::getConnectionResolver();
        $exception = null;

        LoadConfiguration::alwaysUse(static fn (Application $application): array => $cachedConfiguration);

        try {
            $this->createApplication();
        } catch (RuntimeException $cause) {
            $exception = $cause;
        } finally {
            LoadConfiguration::alwaysUse(null);
            Container::setInstance($originalContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($originalFacadeApplication);
            Model::setEventDispatcher($originalEventDispatcher);
            Model::setConnectionResolver($originalConnectionResolver);
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('The application configuration is cached. Run `php artisan config:clear` before running tests.', $exception->getMessage());
        $this->assertSame($originalContainer, Container::getInstance());
        $this->assertSame($originalFacadeApplication, Facade::getFacadeApplication());
        $this->assertSame($originalEventDispatcher, Model::getEventDispatcher());
        $this->assertSame($originalConnectionResolver, Model::getConnectionResolver());
    }
}
