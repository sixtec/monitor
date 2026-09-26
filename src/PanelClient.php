<?php

namespace Sixtec\Monitor;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the report to the monitor panel, with the push key in the
 * `Authorization: Bearer` header. It never throws: the panel is what alerts
 * about a silent agent ("sem sinal"). Failures go to the local log — with the
 * host, never the key.
 */
class PanelClient
{
    public function configured(): bool
    {
        return filled(config('sixtec-monitor.url')) && filled(config('sixtec-monitor.key'));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{sent: bool, status: ?int, state: ?string, error: ?string}
     */
    public function send(array $report): array
    {
        $url = (string) config('sixtec-monitor.url');

        try {
            $response = Http::withToken((string) config('sixtec-monitor.key'))
                ->acceptJson()
                ->withUserAgent('sixtec-monitor/'.Report::AGENT_VERSION)
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url, $report);
        } catch (Throwable $error) {
            Log::warning('sixtec/monitor: the panel did not respond.', ['host' => parse_url($url, PHP_URL_HOST), 'error' => $error->getMessage()]);

            return ['sent' => false, 'status' => null, 'state' => null, 'error' => 'The panel did not respond.'];
        }

        if ($response->failed()) {
            $error = match ($response->status()) {
                404 => 'Push key rejected: check SIXTEC_MONITOR_KEY and that the monitor is set to "Agente por cron" in the panel.',
                422 => 'The panel could not read the report: '.(string) $response->json('error'),
                429 => 'Too many pushes: the panel accepts 30 per minute.',
                default => "The panel answered HTTP {$response->status()}.",
            };

            Log::warning('sixtec/monitor: push rejected.', ['host' => parse_url($url, PHP_URL_HOST), 'status' => $response->status()]);

            return ['sent' => false, 'status' => $response->status(), 'state' => null, 'error' => $error];
        }

        return ['sent' => true, 'status' => $response->status(), 'state' => $response->json('state'), 'error' => null];
    }
}
