<?php

namespace Sixtec\Monitor\Probes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Illuminate\Support\Str;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;
use Sixtec\Monitor\Status;
use Throwable;

/**
 * The app's file disk (local, S3, Spaces): writes, reads and deletes a small
 * file. The result is cached for a few minutes — on a bucket, each test is
 * three billed requests.
 */
class Storage implements Probe
{
    public function check(): iterable
    {
        $disk = (string) (config('sixtec-monitor.storage.disk') ?: config('filesystems.default'));
        $minutes = max(1, (int) config('sixtec-monitor.storage.every_minutes'));

        $outcome = Cache::remember("sixtec-monitor:storage:{$disk}", now()->addMinutes($minutes), fn (): array => $this->test($disk));

        yield new Result('storage', "Storage ({$disk})", Status::from($outcome['status']), $outcome['message'], $outcome['latency_ms']);
    }

    /**
     * @return array{status: string, message: string, latency_ms: ?int}
     */
    private function test(string $disk): array
    {
        $path = '.sixtec-monitor/probe-'.Str::random(8).'.txt';
        $contents = Str::random(16);
        $start = hrtime(true);

        try {
            $storage = StorageFacade::disk($disk);
            $storage->put($path, $contents);
            $read = $storage->get($path);
            $storage->delete($path);
        } catch (Throwable) {
            return ['status' => 'critical', 'message' => 'Could not write and read on the disk.', 'latency_ms' => null];
        }

        $ms = (int) round((hrtime(true) - $start) / 1e6);

        return $read === $contents
            ? ['status' => $ms > 3000 ? 'warning' : 'ok', 'message' => "Write and read in {$ms} ms", 'latency_ms' => $ms]
            : ['status' => 'critical', 'message' => 'The written file did not come back.', 'latency_ms' => $ms];
    }
}
