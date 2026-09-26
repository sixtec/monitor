<?php

namespace Sixtec\Monitor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Sixtec\Monitor\Console\InstallAgent;
use Sixtec\Monitor\Console\RunAgent;
use Sixtec\Monitor\Console\SendReport;
use Sixtec\Monitor\Server\ServerCollector;

/**
 * Registers the package: config, commands and two scheduler tasks in the
 * app — the heartbeat checked by the Scheduler probe and, in `scheduler`
 * mode, the report push.
 */
class MonitorServiceProvider extends ServiceProvider
{
    public const HEARTBEAT_KEY = 'sixtec-monitor:scheduler:heartbeat';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sixtec-monitor.php', 'sixtec-monitor');

        $this->app->singleton(ServerCollector::class, fn (): ServerCollector => new ServerCollector);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sixtec-monitor.php' => config_path('sixtec-monitor.php'),
        ], 'sixtec-monitor-config');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([SendReport::class, RunAgent::class, InstallAgent::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (blank(config('sixtec-monitor.key'))) {
                return;
            }

            $schedule->call(fn () => Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp()))
                ->name('sixtec-monitor:heartbeat')
                ->everyMinute();

            if (config('sixtec-monitor.mode') === 'scheduler') {
                $schedule->command('sixtec:monitor:send --scheduled')
                    ->name('sixtec-monitor:send')
                    ->everyMinute()
                    ->withoutOverlapping(5);
            }
        });
    }
}
