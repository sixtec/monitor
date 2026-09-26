<?php

namespace Sixtec\Monitor\Server;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads Linux server metrics: CPU, memory, swap, disk, load, uptime and the
 * systemd services. Everything comes from /proc and PHP functions — nothing
 * to install. A reading is kept until `forget()`, so the report and the probe
 * share it.
 *
 * The root is configurable only so tests can read a fake /proc.
 */
class ServerCollector
{
    /** @var array<string, mixed>|null */
    private ?array $reading = null;

    public function __construct(
        private string $root = '/',
        private float $cpuSampleSeconds = 1.0,
    ) {}

    public function available(): bool
    {
        // No /proc (macOS, Windows): nothing to read.
        return is_readable($this->path('proc/meminfo'));
    }

    public function forget(): void
    {
        $this->reading = null;
    }

    /**
     * @param  list<string>  $services
     * @return array{os: ?string, cores: int, uptime_seconds: ?int, cpu_percent: ?float, load: list<float>, memory_total_bytes: int, memory_used_bytes: int, swap_total_bytes: int, swap_used_bytes: int, disk_total_bytes: int, disk_used_bytes: int, services: list<array{name: string, state: string}>}
     */
    public function read(array $services = []): array
    {
        return $this->reading ??= [
            'os' => $this->operatingSystem(),
            'cores' => $this->cores(),
            'uptime_seconds' => $this->uptime(),
            'cpu_percent' => $this->cpu(),
            'load' => $this->load(),
            ...$this->memory(),
            ...$this->disk(),
            'services' => $this->services($services),
        ];
    }

    private function cpu(): ?float
    {
        $first = $this->cpuTotals();
        usleep((int) ($this->cpuSampleSeconds * 1_000_000));
        $second = $this->cpuTotals();

        if ($first === null || $second === null) {
            return null;
        }

        $total = $second[0] - $first[0];
        $idle = $second[1] - $first[1];

        return $total > 0 ? round(($total - $idle) / $total * 100, 1) : null;
    }

    /**
     * @return array{0: int, 1: int}|null total and idle (idle + iowait)
     */
    private function cpuTotals(): ?array
    {
        $line = collect($this->lines('proc/stat'))->first(fn (string $line): bool => str_starts_with($line, 'cpu '));

        if ($line === null) {
            return null;
        }

        $values = array_map('intval', array_slice(preg_split('/\s+/', trim($line)) ?: [], 1));

        return [array_sum($values), ($values[3] ?? 0) + ($values[4] ?? 0)];
    }

    /**
     * @return array{memory_total_bytes: int, memory_used_bytes: int, swap_total_bytes: int, swap_used_bytes: int}
     */
    private function memory(): array
    {
        $info = [];

        foreach ($this->lines('proc/meminfo') as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $parts)) {
                $info[$parts[1]] = (int) $parts[2] * 1024;
            }
        }

        $total = $info['MemTotal'] ?? 0;
        $available = $info['MemAvailable'] ?? (($info['MemFree'] ?? 0) + ($info['Cached'] ?? 0));

        return [
            'memory_total_bytes' => $total,
            'memory_used_bytes' => max(0, $total - $available),
            'swap_total_bytes' => $info['SwapTotal'] ?? 0,
            'swap_used_bytes' => max(0, ($info['SwapTotal'] ?? 0) - ($info['SwapFree'] ?? 0)),
        ];
    }

    /**
     * @return array{disk_total_bytes: int, disk_used_bytes: int}
     */
    private function disk(): array
    {
        $total = @disk_total_space($this->root);
        $free = @disk_free_space($this->root);

        return [
            'disk_total_bytes' => (int) ($total ?: 0),
            'disk_used_bytes' => (int) max(0, ($total ?: 0) - ($free ?: 0)),
        ];
    }

    /**
     * @return list<float>
     */
    private function load(): array
    {
        $parts = preg_split('/\s+/', trim((string) @file_get_contents($this->path('proc/loadavg'))));

        return array_map('floatval', array_slice($parts ?: [], 0, 3));
    }

    private function cores(): int
    {
        return max(1, count(array_filter($this->lines('proc/cpuinfo'), fn (string $line): bool => str_starts_with($line, 'processor'))));
    }

    private function uptime(): ?int
    {
        $contents = @file_get_contents($this->path('proc/uptime'));

        return $contents === false ? null : (int) (float) $contents;
    }

    private function operatingSystem(): ?string
    {
        foreach ($this->lines('etc/os-release') as $line) {
            if (str_starts_with($line, 'PRETTY_NAME=')) {
                return trim(substr($line, 12), "\"'");
            }
        }

        return php_uname('s').' '.php_uname('r');
    }

    /**
     * State of the watched services that exist on the server. Without
     * systemctl (or with proc_open disabled by the host), an empty list.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, state: string}>
     */
    private function services(array $names): array
    {
        if ($names === []) {
            return [];
        }

        try {
            $installed = Process::timeout(5)->run(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager']);

            if (! $installed->successful()) {
                return [];
            }

            $existing = collect(explode("\n", $installed->output()))
                ->map(fn (string $line): string => (string) preg_replace('/\.service$/', '', (string) strtok(trim($line), " \t")))
                ->filter()
                ->all();

            $watched = array_values(array_intersect($names, $existing));

            if ($watched === []) {
                return [];
            }

            // is-active exits non-zero when any unit is down: the output is
            // still valid, one line per unit.
            $states = explode("\n", trim(Process::timeout(5)->run(['systemctl', 'is-active', ...$watched])->output()));
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (string $name, int $index): array => [
            'name' => $name,
            'state' => trim($states[$index] ?? 'unknown'),
        ], $watched, array_keys($watched));
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        $contents = @file_get_contents($this->path($file));

        return $contents === false ? [] : explode("\n", $contents);
    }

    private function path(string $relative): string
    {
        return rtrim($this->root, '/').'/'.$relative;
    }
}
