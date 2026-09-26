<?php

namespace Sixtec\Monitor\Probes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;

/**
 * Queues: pending jobs per queue, the age of the oldest one (database queue)
 * and the jobs that failed in the last hour. A database queue with an old job
 * sitting still is the classic sign of a dead worker.
 */
class Queues implements Probe
{
    public function check(): iterable
    {
        $connection = (string) (config('sixtec-monitor.queues.connection') ?: config('queue.default'));
        $driver = (string) config("queue.connections.{$connection}.driver");

        if (in_array($driver, ['sync', 'null', ''], true)) {
            yield Result::skipped('queues', 'Queues', "The {$connection} connection does not queue ({$driver}).");

            return;
        }

        $config = config('sixtec-monitor.queues');
        $pending = [];
        $wait = null;

        foreach ((array) $config['names'] as $queue) {
            $pending[$queue] = Queue::connection($connection)->size($queue);
        }

        if ($driver === 'database') {
            $oldest = DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->whereIn('queue', array_keys($pending))
                ->whereNull('reserved_at')
                ->min('available_at');
            $wait = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : 0;
        }

        $total = array_sum($pending);
        $status = Result::byThreshold($total, $config['pending']['warning'], $config['pending']['critical']);

        if ($wait !== null) {
            $byWait = Result::byThreshold($wait, $config['wait_seconds']['warning'], $config['wait_seconds']['critical']);
            $status = $byWait->severity() > $status->severity() ? $byWait : $status;
        }

        $parts = array_map(fn (string $queue, int $count): string => "{$queue}: {$count}", array_keys($pending), $pending);
        $message = implode(', ', $parts).($wait ? ' · waiting '.$this->duration($wait) : '');

        yield new Result('queues', 'Queues', $status, $message, null, ['pending' => $total, 'wait_seconds' => $wait]);

        yield from $this->failures();
    }

    /**
     * @return iterable<Result>
     */
    private function failures(): iterable
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $connection = config('queue.failed.database');

        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) || ! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        $failed = DB::connection($connection)->table($table)->where('failed_at', '>=', now()->subHour())->count();
        $thresholds = config('sixtec-monitor.queues.failed_per_hour');

        yield new Result(
            'failed_jobs',
            'Failed jobs (1h)',
            Result::byThreshold($failed, $thresholds['warning'], $thresholds['critical']),
            match ($failed) {
                0 => 'No failures in the last hour',
                1 => '1 job failed in the last hour',
                default => "{$failed} jobs failed in the last hour",
            },
            null,
            ['failed' => $failed],
        );
    }

    private function duration(int $seconds): string
    {
        return $seconds < 120 ? "{$seconds}s" : (int) round($seconds / 60).' min';
    }
}
