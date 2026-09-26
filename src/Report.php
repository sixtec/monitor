<?php

namespace Sixtec\Monitor;

use Illuminate\Support\Str;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Probes\Server;
use Sixtec\Monitor\Server\ServerCollector;
use Throwable;

/**
 * Runs the probes and builds the `sixtec.health/1` report — what the monitor
 * panel reads: overall status (the worst component), the component list, the
 * deploy version and, on a VPS, the server metrics.
 *
 * A probe that throws becomes a critical component with a generic message:
 * the exception text may carry a host, a user or a path, and the report
 * leaves the server.
 */
class Report
{
    public const CONTRACT = 'sixtec.health/1';

    public const AGENT_VERSION = '1.0.0';

    public function __construct(private ServerCollector $collector) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $this->collector->forget();

        $results = [];

        foreach ([...(array) config('sixtec-monitor.probes'), ...(array) config('sixtec-monitor.extra_probes')] as $class) {
            $results = [...$results, ...$this->run($class)];
        }

        $worst = array_reduce(
            $results,
            fn (Status $worst, Result $result): Status => $result->status->severity() > $worst->severity() ? $result->status : $worst,
            Status::Ok,
        );

        $report = [
            'contract' => self::CONTRACT,
            'status' => $worst === Status::Unknown ? Status::Ok->value : $worst->value,
            'system' => (string) config('app.name'),
            'environment' => (string) app()->environment(),
            'checked_at' => now()->toIso8601String(),
            'version' => array_filter([
                'commit' => $this->commit(),
                'agent' => self::AGENT_VERSION,
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'mode' => (string) config('sixtec-monitor.mode'),
            ]),
            'checks' => array_map(fn (Result $result): array => $result->toArray(), $results),
        ];

        if (Server::enabled() && $this->collector->available()) {
            $report['server'] = $this->collector->read((array) config('sixtec-monitor.server.services'));
        }

        return $report;
    }

    /**
     * @param  class-string<Probe>  $class
     * @return list<Result>
     */
    private function run(string $class): array
    {
        try {
            /** @var Probe $probe */
            $probe = app($class);

            return array_values([...$probe->check()]);
        } catch (Throwable $error) {
            return [Result::critical(Str::snake(class_basename($class)), Str::headline(class_basename($class)), 'Failed: '.class_basename($error).'.')];
        }
    }

    /** Deploy commit: config, REVISION file or the app's .git. */
    private function commit(): ?string
    {
        $configured = config('sixtec-monitor.version');

        if (is_string($configured) && $configured !== '') {
            return Str::limit($configured, 40, '');
        }

        $revision = base_path('REVISION');

        if (is_readable($revision)) {
            return Str::limit(trim((string) file_get_contents($revision)), 40, '') ?: null;
        }

        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $reference = trim((string) file_get_contents($head));

        if (str_starts_with($reference, 'ref: ')) {
            $file = base_path('.git/'.substr($reference, 5));
            $reference = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        }

        return $reference !== '' ? substr($reference, 0, 12) : null;
    }
}
