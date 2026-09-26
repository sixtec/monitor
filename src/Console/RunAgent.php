<?php

namespace Sixtec\Monitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sixtec\Monitor\PanelClient;
use Sixtec\Monitor\Report;
use Throwable;

/**
 * The service: sends the report every `interval` seconds until `max_time`
 * and exits — systemd (Restart=always) starts it again, with clean memory
 * and fresh config. SIGTERM (systemctl stop, a deploy) exits after the push
 * in progress, never halfway through.
 */
class RunAgent extends Command
{
    protected $signature = 'sixtec:monitor:agent
                            {--max-time= : Seconds before renewing itself (default: config)}
                            {--times= : Stop after N pushes (tests)}';

    protected $description = 'Agent service: sends the health report to the panel at an interval';

    private bool $stop = false;

    public function handle(Report $report, PanelClient $panel): int
    {
        if (! $panel->configured()) {
            $this->error('SIXTEC_MONITOR_KEY is not set. Run php artisan sixtec:monitor:install.');

            // Non-zero exit: systemd records the failure, and RestartSec
            // keeps it from restarting in a tight loop.
            return self::FAILURE;
        }

        $this->listenForSignals();

        $interval = max(10, (int) config('sixtec-monitor.interval'));
        $deadline = microtime(true) + (int) ($this->option('max-time') ?: config('sixtec-monitor.max_time'));
        $times = $this->option('times') !== null ? (int) $this->option('times') : null;
        $sent = 0;

        $this->info("sixtec-monitor: sending every {$interval}s to ".parse_url((string) config('sixtec-monitor.url'), PHP_URL_HOST).'.');

        while (! $this->stop && microtime(true) < $deadline) {
            $start = microtime(true);

            try {
                $result = $panel->send($report->build());
                $this->line(now()->format('H:i:s').' '.($result['sent'] ? 'sent ('.($result['state'] ?? '?').')' : 'failed: '.$result['error']));
            } catch (Throwable $error) {
                $this->line(now()->format('H:i:s').' failed to build the report: '.class_basename($error));
            }

            $this->disconnectDatabases();

            if ($times !== null && ++$sent >= $times) {
                break;
            }

            $this->sleepUntil(min($start + $interval, $deadline));
        }

        return self::SUCCESS;
    }

    /**
     * A connection held for an hour turns into "MySQL server has gone away":
     * each push opens its own. In-memory SQLite stays — disconnecting would
     * wipe the database.
     */
    private function disconnectDatabases(): void
    {
        foreach (DB::getConnections() as $name => $connection) {
            if ($connection->getDatabaseName() !== ':memory:') {
                DB::disconnect($name);
            }
        }
    }

    private function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop = true;
            });
        }
    }

    /** Sleeps in short slices so a SIGTERM is honoured quickly. */
    private function sleepUntil(float $until): void
    {
        while (! $this->stop && microtime(true) < $until) {
            usleep(250_000);
        }
    }
}
