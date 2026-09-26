<?php

namespace Sixtec\Monitor\Contracts;

use Sixtec\Monitor\Result;

/**
 * A check. It may return several results (one per queue, one per system
 * service) or none (the probe does not apply to this app — no Horizon, for
 * instance).
 *
 * To add the app's own probes (payment gateway, third-party API), implement
 * this interface and list the class in `config/sixtec-monitor.php` →
 * `extra_probes`. An exception thrown inside `check()` becomes a critical
 * component with a generic message — no need to handle it.
 */
interface Probe
{
    /**
     * @return iterable<Result>
     */
    public function check(): iterable;
}
