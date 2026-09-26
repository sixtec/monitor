<?php

namespace Sixtec\Monitor\Probes;

use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;
use Sixtec\Monitor\Server\ServerCollector;
use Sixtec\Monitor\Status;

/**
 * The server as components: CPU, memory, disk, load and each watched systemd
 * service. Only runs with the server enabled (config `server.enabled`;
 * automatic = service mode) on a Linux machine with /proc.
 */
class Server implements Probe
{
    public function __construct(private ServerCollector $collector) {}

    public static function enabled(): bool
    {
        $enabled = config('sixtec-monitor.server.enabled');

        if ($enabled === null || $enabled === '') {
            return config('sixtec-monitor.mode') === 'service';
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    public function check(): iterable
    {
        if (! self::enabled() || ! $this->collector->available()) {
            return;
        }

        $config = config('sixtec-monitor.server');
        $reading = $this->collector->read((array) $config['services']);

        if ($reading['cpu_percent'] !== null) {
            $cpu = $reading['cpu_percent'];
            yield new Result('cpu', 'CPU', Result::byThreshold($cpu, $config['cpu']['warning'], $config['cpu']['critical']), $this->number($cpu)."% in use · {$reading['cores']} cores", null, ['percent' => $cpu]);
        }

        if ($reading['memory_total_bytes'] > 0) {
            $memory = 100 * $reading['memory_used_bytes'] / $reading['memory_total_bytes'];
            yield new Result('memory', 'Memory', Result::byThreshold($memory, $config['memory']['warning'], $config['memory']['critical']), $this->number($memory).'% of '.$this->bytes($reading['memory_total_bytes']), null, ['percent' => round($memory, 1)]);
        }

        if ($reading['disk_total_bytes'] > 0) {
            $disk = 100 * $reading['disk_used_bytes'] / $reading['disk_total_bytes'];
            yield new Result('disk', 'Disk', Result::byThreshold($disk, $config['disk']['warning'], $config['disk']['critical']), $this->number($disk).'% of '.$this->bytes($reading['disk_total_bytes']), null, ['percent' => round($disk, 1)]);
        }

        if ($reading['load'] !== []) {
            // Load above the core count means processes queueing for CPU.
            $perCore = $reading['load'][0] / max(1, $reading['cores']);
            yield new Result('load', 'Load', $perCore >= 2 ? Status::Warning : Status::Ok, 'load '.implode(' · ', array_map(fn (float $value): string => $this->number($value, 2), $reading['load'])), null, ['load_1' => $reading['load'][0]]);
        }

        foreach ($reading['services'] as $service) {
            $running = $service['state'] === 'active';
            yield new Result(
                'service_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($service['name'])),
                $service['name'],
                $running ? Status::Ok : Status::Critical,
                $running ? 'Running' : "Service {$service['state']}",
            );
        }
    }

    private function number(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    private function bytes(int $bytes): string
    {
        $gb = $bytes / 1024 ** 3;

        return $gb >= 1 ? $this->number($gb).' GB' : $this->number($bytes / 1024 ** 2, 0).' MB';
    }
}
