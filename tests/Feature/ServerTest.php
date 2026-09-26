<?php

namespace Sixtec\Monitor\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Sixtec\Monitor\Report;
use Sixtec\Monitor\Server\ServerCollector;
use Sixtec\Monitor\Tests\TestCase;

/**
 * Server metrics read from a fake /proc: the collector takes the root path.
 */
class ServerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root = sys_get_temp_dir().'/sixtec-monitor-'.uniqid();
        mkdir($this->root.'/proc', 0777, true);
        mkdir($this->root.'/etc', 0777, true);

        file_put_contents($this->root.'/proc/meminfo', "MemTotal:        4000000 kB\nMemFree:          100000 kB\nMemAvailable:     400000 kB\nSwapTotal:       1000000 kB\nSwapFree:         750000 kB\n");
        file_put_contents($this->root.'/proc/stat', "cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 100 0 100 800 0 0 0 0 0 0\n");
        file_put_contents($this->root.'/proc/loadavg', "0.50 0.40 0.30 1/200 12345\n");
        file_put_contents($this->root.'/proc/cpuinfo', "processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\nmodel name\t: x\n");
        file_put_contents($this->root.'/proc/uptime', "86400.00 170000.00\n");
        file_put_contents($this->root.'/etc/os-release', "NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n");

        $this->app->singleton(ServerCollector::class, fn (): ServerCollector => new ServerCollector($this->root, 0));

        Process::fake([
            '*list-unit-files*' => Process::result("nginx.service enabled enabled\nphp8.4-fpm.service enabled enabled\nssh.service enabled enabled\n"),
            '*is-active*' => Process::result("active\nfailed\n", exitCode: 3),
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_service_mode_sends_server_metrics_and_thresholded_components(): void
    {
        config(['sixtec-monitor.mode' => 'service']);

        $report = app(Report::class)->build();
        $components = collect($report['checks'])->keyBy('key');

        // 3.6 of 4 GB = 90%: above the 85% warning, below the 95% critical.
        $this->assertSame('warning', $components['memory']['status']);
        $this->assertStringStartsWith('90.0% of 3.8 GB', $components['memory']['message']);
        $this->assertSame('ok', $components['load']['status']);
        $this->assertSame('ok', $components['service_nginx']['status']);
        $this->assertSame('critical', $components['service_php8_4_fpm']['status']);
        $this->assertSame('Service failed', $components['service_php8_4_fpm']['message']);
        $this->assertFalse($components->has('service_ssh'), 'Only watched services are reported.');

        $this->assertSame('Ubuntu 24.04 LTS', $report['server']['os']);
        $this->assertSame(2, $report['server']['cores']);
        $this->assertSame([0.5, 0.4, 0.3], $report['server']['load']);
        $this->assertSame(250000 * 1024, $report['server']['swap_used_bytes']);
        $this->assertSame([['name' => 'nginx', 'state' => 'active'], ['name' => 'php8.4-fpm', 'state' => 'failed']], $report['server']['services']);
        $this->assertSame('critical', $report['status']);
    }

    public function test_scheduler_mode_does_not_measure_a_shared_server(): void
    {
        $report = app(Report::class)->build();

        $this->assertArrayNotHasKey('server', $report);
        $this->assertNotContains('memory', array_column($report['checks'], 'key'));
    }

    public function test_server_can_be_enabled_by_hand(): void
    {
        config(['sixtec-monitor.server.enabled' => 'true']);

        $this->assertArrayHasKey('server', app(Report::class)->build());
    }

    public function test_without_systemctl_the_rest_still_works(): void
    {
        config(['sixtec-monitor.mode' => 'service']);
        Process::fake(['*list-unit-files*' => Process::result('', 'systemctl: command not found', 127)]);

        $report = app(Report::class)->build();

        $this->assertSame([], $report['server']['services']);
        $this->assertContains('memory', array_column($report['checks'], 'key'));
    }
}
