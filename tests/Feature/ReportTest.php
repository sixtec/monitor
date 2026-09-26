<?php

namespace Sixtec\Monitor\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\MonitorServiceProvider;
use Sixtec\Monitor\Report;
use Sixtec\Monitor\Result;
use Sixtec\Monitor\Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function components(): array
    {
        return collect(app(Report::class)->build()['checks'])->keyBy('key')->all();
    }

    public function test_builds_the_contract_with_the_default_probes(): void
    {
        config(['sixtec-monitor.version' => 'a1b2c3d4e5f6']);
        Cache::forever(MonitorServiceProvider::HEARTBEAT_KEY, now()->getTimestamp());

        $report = app(Report::class)->build();

        $this->assertSame('sixtec.health/1', $report['contract']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('Lawify', $report['system']);
        $this->assertSame('a1b2c3d4e5f6', $report['version']['commit']);
        $this->assertSame(['database', 'cache', 'queues', 'failed_jobs', 'scheduler', 'storage'], array_column($report['checks'], 'key'));
        $this->assertArrayNotHasKey('server', $report, 'Outside service mode, no server metrics.');
    }

    public function test_a_stuck_database_queue_and_failures_raise_the_status(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subMinutes(5)]);

        $components = $this->components();

        $this->assertSame('critical', $components['queues']['status']);
        $this->assertSame('default: 1 · waiting 20 min', $components['queues']['message']);
        $this->assertSame('warning', $components['failed_jobs']['status']);
        $this->assertSame('1 job failed in the last hour', $components['failed_jobs']['message']);
    }

    public function test_sync_queue_is_skipped(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);

        $this->assertSame('skipped', $this->components()['queues']['status']);
    }

    public function test_scheduler_without_heartbeat_is_unknown_and_stale_is_critical(): void
    {
        $this->assertSame('unknown', $this->components()['scheduler']['status']);

        Cache::forever(MonitorServiceProvider::HEARTBEAT_KEY, now()->subMinutes(10)->getTimestamp());
        $this->assertSame('critical', $this->components()['scheduler']['status']);

        // On shared hosting cron runs every 15 min: 10 min is normal there.
        config(['sixtec-monitor.scheduler.expected_minutes' => 15]);
        $this->assertSame('ok', $this->components()['scheduler']['status']);
    }

    public function test_app_probes_are_included_and_a_throwing_one_becomes_critical_without_leaking(): void
    {
        config(['sixtec-monitor.extra_probes' => [PaymentProbe::class, ThrowingProbe::class]]);

        $components = $this->components();

        $this->assertSame('warning', $components['asaas']['status']);
        $this->assertSame('critical', $components['throwing_probe']['status']);
        $this->assertSame('Failed: RuntimeException.', $components['throwing_probe']['message']);
        $this->assertStringNotContainsString('secret', (string) json_encode($components));
    }

    public function test_storage_result_is_cached_between_runs(): void
    {
        $this->assertSame('ok', $this->components()['storage']['status']);

        Storage::shouldReceive('disk')->never();
        $this->assertSame('ok', $this->components()['storage']['status']);
    }
}

class PaymentProbe implements Probe
{
    public function check(): iterable
    {
        yield Result::warning('asaas', 'Asaas', 'Slow response (2.4 s).', 2400);
    }
}

class ThrowingProbe implements Probe
{
    public function check(): iterable
    {
        throw new RuntimeException('mysql://root:secret@10.0.0.5 refused');
    }
}
