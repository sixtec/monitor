<?php

namespace Sixtec\Monitor\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sixtec\Monitor\MonitorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.name' => 'Lawify',
            'database.default' => 'testing',
            'cache.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => null],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs'],
            'filesystems.default' => 'local',
            'sixtec-monitor.url' => 'https://panel.test/monitor/health',
            'sixtec-monitor.key' => 'sxs_test-key',
            'sixtec-monitor.mode' => 'scheduler',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
