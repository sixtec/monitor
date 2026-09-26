<?php

namespace Sixtec\Monitor\Probes;

use Illuminate\Support\Facades\DB;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;

/** Default database connection: a timed `SELECT 1`. */
class Database implements Probe
{
    public function check(): iterable
    {
        $start = hrtime(true);
        DB::connection()->select('select 1');
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        $status = Result::byThreshold($ms, config('sixtec-monitor.database.warning_ms'), config('sixtec-monitor.database.critical_ms'));

        yield new Result('database', 'Database', $status, "SELECT 1 in {$ms} ms", $ms);
    }
}
