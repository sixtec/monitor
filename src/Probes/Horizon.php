<?php

namespace Sixtec\Monitor\Probes;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;

/** Horizon, when the app uses it: running, paused or stopped. Without it, nothing. */
class Horizon implements Probe
{
    public function check(): iterable
    {
        if (! interface_exists(MasterSupervisorRepository::class) || ! app()->bound(MasterSupervisorRepository::class)) {
            return;
        }

        $masters = app(MasterSupervisorRepository::class)->all();

        if ($masters === []) {
            yield Result::critical('horizon', 'Horizon', 'No Horizon process running.');

            return;
        }

        $paused = collect($masters)->filter(fn ($master): bool => ($master->status ?? null) === 'paused')->count();

        yield $paused > 0
            ? Result::warning('horizon', 'Horizon', 'Horizon is paused.')
            : Result::ok('horizon', 'Horizon', 'Running.');
    }
}
