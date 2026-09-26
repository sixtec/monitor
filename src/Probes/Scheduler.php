<?php

namespace Sixtec\Monitor\Probes;

use Illuminate\Support\Facades\Cache;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\MonitorServiceProvider;
use Sixtec\Monitor\Result;
use Throwable;

/**
 * Scheduler (cron + schedule:run): the package records a heartbeat on every
 * scheduler run, and this probe checks its age. A routine that stops running
 * (billing, syncs) is a silent failure — this makes it visible.
 */
class Scheduler implements Probe
{
    public function check(): iterable
    {
        try {
            $heartbeat = Cache::get(MonitorServiceProvider::HEARTBEAT_KEY);
        } catch (Throwable) {
            // The heartbeat lives in the cache: with the cache down it cannot
            // be read, which says nothing about the scheduler itself. The
            // Cache probe already reports the outage.
            yield Result::unknown('scheduler', 'Scheduler', 'Heartbeat unreadable: the cache is unavailable.');

            return;
        }

        if (! is_numeric($heartbeat)) {
            yield Result::unknown('scheduler', 'Scheduler', 'No heartbeat recorded yet (has schedule:run run?).');

            return;
        }

        $expected = max(1, (int) config('sixtec-monitor.scheduler.expected_minutes')) * 60;
        $age = max(0, now()->getTimestamp() - (int) $heartbeat);
        $minutes = (int) floor($age / 60);

        yield new Result(
            'scheduler',
            'Scheduler',
            Result::byThreshold($age, $expected * 2 + 60, $expected * 3 + 60),
            $minutes < 1 ? 'Last run less than 1 min ago' : "Last run {$minutes} min ago",
            null,
            ['age_seconds' => $age],
        );
    }
}
