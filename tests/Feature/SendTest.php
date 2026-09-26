<?php

namespace Sixtec\Monitor\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sixtec\Monitor\Tests\TestCase;

class SendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_sends_with_the_key_and_prints_the_panel_state(): void
    {
        Http::fake(['panel.test/*' => Http::response(['received' => true, 'state' => 'ok'], 202)]);

        $this->artisan('sixtec:monitor:send')->expectsOutputToContain('Panel state: ok')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://panel.test/monitores/saude'
            && $request->hasHeader('Authorization', 'Bearer sxs_test-key')
            && $request['contract'] === 'sixtec.health/1'
            && str_starts_with($request->header('User-Agent')[0], 'sixtec-monitor/'));
    }

    public function test_rejected_key_explains_what_to_check_without_logging_the_key(): void
    {
        Http::fake(['panel.test/*' => Http::response(['message' => 'Not Found'], 404)]);
        Log::spy();

        $this->artisan('sixtec:monitor:send')->expectsOutputToContain('Push key rejected')->assertFailed();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains($message.json_encode($context), 'sxs_test-key'));
    }

    public function test_panel_down_does_not_throw(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        $this->artisan('sixtec:monitor:send --scheduled')->assertSuccessful();
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['sixtec-monitor.key' => null]);
        Http::fake();

        $this->artisan('sixtec:monitor:send')->expectsOutputToContain('SIXTEC_MONITOR_KEY is not set')->assertSuccessful();
        $this->artisan('sixtec:monitor:agent')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_show_does_not_send(): void
    {
        Http::fake();

        $this->artisan('sixtec:monitor:send --show')->expectsOutputToContain('Database')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_agent_sends_and_stops(): void
    {
        Http::fake(['panel.test/*' => Http::response(['received' => true, 'state' => 'ok'], 202)]);

        $this->artisan('sixtec:monitor:agent --times=1')->expectsOutputToContain('sent (ok)')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_scheduler_gets_the_heartbeat_and_the_push_only_in_scheduler_mode(): void
    {
        $tasks = collect(app(Schedule::class)->events())->map->description->filter()->values()->all();

        $this->assertSame(['sixtec-monitor:heartbeat', 'sixtec-monitor:send'], $tasks);
    }
}
