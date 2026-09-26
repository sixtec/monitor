<?php

namespace Sixtec\Monitor\Probes;

use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Str;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;

/** Default cache store: writes, reads and forgets a key. */
class Cache implements Probe
{
    public function check(): iterable
    {
        $key = 'sixtec-monitor:probe:'.Str::random(8);
        $value = Str::random(16);

        $start = hrtime(true);
        CacheFacade::put($key, $value, 60);
        $read = CacheFacade::get($key);
        CacheFacade::forget($key);
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        $label = 'Cache ('.config('cache.default').')';

        yield $read === $value
            ? Result::ok('cache', $label, "Write and read in {$ms} ms", $ms)
            : Result::critical('cache', $label, 'The stored value did not come back.', $ms);
    }
}
