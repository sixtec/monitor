<?php

namespace Sixtec\Monitor\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Sixtec\Monitor\Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->directory = sys_get_temp_dir().'/sixtec-monitor-install-'.uniqid();
        mkdir($this->directory.'/storage/app', 0777, true);
        file_put_contents($this->directory.'/.env', "APP_NAME=Lawify\nSIXTEC_MONITOR_MODE=service\n");
        $this->app->useEnvironmentPath($this->directory);
        $this->app->useStoragePath($this->directory.'/storage');
        config(['sixtec-monitor.key' => null]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));

        parent::tearDown();
    }

    public function test_scheduler_mode_writes_env_after_the_test_report(): void
    {
        Http::fake(['panel.test/*' => Http::response(['received' => true, 'state' => 'ok'], 202)]);

        $this->artisan('sixtec:monitor:install --key=sxs_new --mode=scheduler --cron-minutes=15')
            ->expectsOutputToContain('Test report accepted')
            ->expectsOutputToContain('set the agent interval to 15 minute(s)')
            ->assertSuccessful();

        $env = (string) file_get_contents($this->directory.'/.env');
        $this->assertStringContainsString("SIXTEC_MONITOR_KEY=sxs_new\n", $env);
        $this->assertStringContainsString("SIXTEC_MONITOR_MODE=scheduler\n", $env);
        $this->assertStringContainsString("SIXTEC_MONITOR_SCHEDULER_MINUTES=15\n", $env);
        $this->assertSame(1, substr_count($env, 'SIXTEC_MONITOR_MODE='), 'Replaces the existing line instead of duplicating it.');
    }

    public function test_a_rejected_test_report_installs_nothing(): void
    {
        Http::fake(['panel.test/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->artisan('sixtec:monitor:install --key=sxs_wrong --mode=scheduler')->assertFailed();

        $this->assertStringNotContainsString('sxs_wrong', (string) file_get_contents($this->directory.'/.env'));
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        $this->artisan('sixtec:monitor:install --key=whatever --mode=scheduler')->assertFailed();
    }

    public function test_service_mode_without_root_writes_the_unit_and_prints_sudo(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root, the command would really install it.');
        }

        $this->artisan('sixtec:monitor:install --key=sxs_new --mode=service --skip-test')
            ->expectsOutputToContain('sudo systemctl enable --now sixtec-monitor-lawify.service')
            ->assertSuccessful();

        $unit = (string) file_get_contents($this->directory.'/storage/app/sixtec-monitor/sixtec-monitor-lawify.service');
        $this->assertStringContainsString('ExecStart='.PHP_BINARY.' '.base_path().'/artisan sixtec:monitor:agent', $unit);
        $this->assertStringContainsString('Restart=always', $unit);
        $this->assertStringNotContainsString('{{', $unit);
    }
}
