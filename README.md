# Sixtec Monitor

The monitoring agent for the Laravel apps Sixtec runs. Like the Sentry SDK, it
lives inside the app; unlike it, it also installs a **Linux service** that keeps
reporting the health of the **application** and the **server** to the Sixtec
monitor panel.

- **Application:** database, cache, queues and failed jobs, Horizon, scheduler,
  storage — plus the app's own probes (payment gateway, third-party APIs…).
- **Server** (VPS): CPU, memory, swap, disk, load and the systemd services
  (nginx, php-fpm, MySQL, Redis, supervisor…), read from `/proc`.

The panel shows each component, alerts the team on critical, and marks the
system as **"no signal"** when reports stop arriving — silence is a signal too.

Laravel 11, 12 and 13 · PHP 8.2+.

## Install

```bash
composer require sixtec/monitor
php artisan sixtec:monitor:install
```

`install` asks for the **push key** (generated in the panel: *Operação ›
Monitores › the system › Editar › "Agente por cron"*, then *"Como o agente
envia" › "Gerar chave de envio"*), sends a test report and turns on continuous
reporting:

| Where | What `install` does | Interval |
| --- | --- | --- |
| **VPS with systemd** | Generates the `sixtec-monitor-<app>` service. As root it installs and starts it; otherwise it writes the unit to `storage/app/sixtec-monitor/` and prints the three `sudo` commands. | every minute |
| **Shared hosting** (no systemd) | Uses the app's own scheduler, which cron already runs. Nothing to install on the system. | the cron's |

Then set the agent interval in the panel to match (1 minute for the service;
the cron interval on shared hosting — `--cron-minutes=15`).

```bash
# non-interactive
php artisan sixtec:monitor:install --key=sxs_... --mode=service
php artisan sixtec:monitor:install --key=sxs_... --mode=scheduler --cron-minutes=15
```

### The service

```bash
systemctl status sixtec-monitor-lawify
journalctl -u sixtec-monitor-lawify -f
```

It runs `php artisan sixtec:monitor:agent` as the owner of the app directory,
sends every `SIXTEC_MONITOR_INTERVAL` seconds and exits after
`SIXTEC_MONITOR_MAX_TIME` (1 hour) — systemd (`Restart=always`) starts it
again with clean memory and fresh config, like `queue:work --max-time`. A
`SIGTERM` (deploy, `systemctl stop`) finishes the push in progress first.

After a deploy that changes `.env` or the package version, restart it:
`sudo systemctl restart sixtec-monitor-<app>`.

## Commands

| Command | |
| --- | --- |
| `sixtec:monitor:install` | Writes key and mode to `.env`, sends a test report, installs the service or explains the scheduler setup. |
| `sixtec:monitor:send` | One push. `--show` prints the report without sending. |
| `sixtec:monitor:agent` | The service loop (what systemd runs). |

## Configuration

Everything has a sensible default; the `.env` usually only needs the key.

| Variable | Default | |
| --- | --- | --- |
| `SIXTEC_MONITOR_KEY` | — | Push key from the panel. Without it, nothing is sent. |
| `SIXTEC_MONITOR_URL` | `https://sixtec.com.br/monitores/saude` | Panel endpoint. |
| `SIXTEC_MONITOR_MODE` | `scheduler` | `service` or `scheduler` (written by `install`). |
| `SIXTEC_MONITOR_INTERVAL` | `60` | Seconds between pushes (service). |
| `SIXTEC_MONITOR_MAX_TIME` | `3600` | Seconds before the service renews itself. |
| `SIXTEC_MONITOR_VERSION` | — | Deploy commit. Falls back to `REVISION`, then `.git`. |
| `SIXTEC_MONITOR_QUEUES` | `default` | Comma-separated queues to watch. |
| `SIXTEC_MONITOR_QUEUE_CONNECTION` | `queue.default` | Queue connection. |
| `SIXTEC_MONITOR_SCHEDULER_MINUTES` | `1` | How often cron runs `schedule:run`. |
| `SIXTEC_MONITOR_DISK` | `filesystems.default` | Disk tested by the storage probe (every 5 min). |
| `SIXTEC_MONITOR_SERVER` | automatic | Server metrics; on in `service` mode, off in `scheduler` mode (on shared hosting `/proc` shows the whole machine). |

Thresholds, the probe list and the watched systemd services live in the config
file:

```bash
php artisan vendor:publish --tag=sixtec-monitor-config
```

## Your own probes

Implement `Sixtec\Monitor\Contracts\Probe` and list the class in
`extra_probes`. A probe may return several results, or none when it does not
apply. An exception becomes a critical component with a generic message — the
exception text never leaves the server.

```php
use Illuminate\Support\Facades\Http;
use Sixtec\Monitor\Contracts\Probe;
use Sixtec\Monitor\Result;

class AsaasProbe implements Probe
{
    public function check(): iterable
    {
        if (blank(config('services.asaas.key'))) {
            return; // not configured here: no component
        }

        $response = Http::timeout(5)->withToken(config('services.asaas.key'))->get('https://api.asaas.com/v3/myAccount');

        yield $response->successful()
            ? Result::ok('asaas', 'Asaas', 'API reachable', (int) ($response->handlerStats()['total_time'] * 1000))
            : Result::critical('asaas', 'Asaas', "API answered HTTP {$response->status()}");
    }
}
```

```php
// config/sixtec-monitor.php
'extra_probes' => [App\Monitoring\AsaasProbe::class],
```

Statuses: `ok`, `warning`, `critical`, `skipped` (does not apply) and
`unknown` (could not be measured — never alerts).

**Keep messages safe.** They leave the server: no DSN, password, token,
internal host or raw exception text.

## The report

`POST` to the panel with `Authorization: Bearer <key>`, contract
`sixtec.health/1`:

```json
{
  "contract": "sixtec.health/1",
  "status": "warning",
  "system": "Lawify",
  "environment": "production",
  "checked_at": "2026-09-26T11:02:13-03:00",
  "version": { "commit": "a1b2c3d", "agent": "1.0.0", "laravel": "13.9.0", "php": "8.4.25", "mode": "service" },
  "checks": [
    { "key": "database", "label": "Database", "status": "ok", "message": "SELECT 1 in 3 ms", "latency_ms": 3, "metrics": {} },
    { "key": "queues", "label": "Queues", "status": "warning", "message": "default: 640 · waiting 6 min", "latency_ms": null, "metrics": { "pending": 640, "wait_seconds": 372 } }
  ],
  "server": { "os": "Ubuntu 24.04 LTS", "cores": 2, "cpu_percent": 12.5, "load": [0.5, 0.4, 0.3], "memory_total_bytes": 4096000000, "memory_used_bytes": 2100000000, "disk_total_bytes": 80000000000, "disk_used_bytes": 31000000000, "services": [{ "name": "nginx", "state": "active" }] }
}
```

`server` is only sent when server metrics are enabled.

## Security

- The key only travels in the `Authorization` header and is never logged
  (failures log the host only). The panel stores only its SHA-256.
- The service runs as the app directory owner, with `NoNewPrivileges=true`.
- Pushing never throws: a network hiccup is logged locally, and the panel
  flags the silence.

## Testing

```bash
composer test
```

## License

MIT.
