<?php

declare(strict_types=1);

namespace Zakobo\WebhookOutbox\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\WebhookServer\WebhookServerServiceProvider;
use Zakobo\WebhookOutbox\WebhookOutboxServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = ['mysql'];

    protected function getPackageProviders($app): array
    {
        return [
            WebhookServerServiceProvider::class,
            WebhookOutboxServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'mysql');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(__DIR__.'/../database/migrations');
        $this->app->make('migrator')->path(__DIR__.'/Migrations');
    }
}
