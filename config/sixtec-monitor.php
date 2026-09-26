<?php

use Sixtec\Monitor\Probes\Cache;
use Sixtec\Monitor\Probes\Database;
use Sixtec\Monitor\Probes\Horizon;
use Sixtec\Monitor\Probes\Queues;
use Sixtec\Monitor\Probes\Scheduler;
use Sixtec\Monitor\Probes\Server;
use Sixtec\Monitor\Probes\Storage;

return [

    /*
    |--------------------------------------------------------------------------
    | Monitor panel
    |--------------------------------------------------------------------------
    |
    | Where the report goes, and the monitor's push key, generated in the panel
    | (Operação › Monitores › the system › "Como o agente envia"). Without a
    | key, the agent sends nothing.
    |
    */

    'url' => env('SIXTEC_MONITOR_URL', 'https://sixtec.com.br/monitores/saude'),

    'key' => env('SIXTEC_MONITOR_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | `service`: a systemd service (`php artisan sixtec:monitor:agent`) keeps
    | running and sends every `interval`. This is the mode for VPS.
    |
    | `scheduler`: the app's own Laravel scheduler (`schedule:run`, via cron)
    | sends. This is the mode for shared hosting, where there is no systemd —
    | the interval becomes the cron's.
    |
    | `sixtec:monitor:install` detects the mode and writes it to .env.
    |
    */

    'mode' => env('SIXTEC_MONITOR_MODE', 'scheduler'),

    'interval' => (int) env('SIXTEC_MONITOR_INTERVAL', 60),

    /*
    | The service renews itself after this many seconds (systemd starts it
    | again), like `queue:work --max-time`: a PHP process running for months
    | piles up memory.
    */
    'max_time' => (int) env('SIXTEC_MONITOR_MAX_TIME', 3600),

    /*
    | Deploy version (commit) sent with the report. When empty, the agent
    | tries the REVISION file at the app root, then .git.
    */
    'version' => env('SIXTEC_MONITOR_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    |
    | The built-in probes and the app's own (`extra_probes`, classes that
    | implement Sixtec\Monitor\Contracts\Probe). Remove what does not apply.
    |
    */

    'probes' => [
        Database::class,
        Cache::class,
        Queues::class,
        Horizon::class,
        Scheduler::class,
        Storage::class,
        Server::class,
    ],

    'extra_probes' => [],

    'database' => [
        'warning_ms' => 200,
        'critical_ms' => 1000,
    ],

    'queues' => [
        // Queue connection (default: queue.default) and the watched queues.
        'connection' => env('SIXTEC_MONITOR_QUEUE_CONNECTION'),
        'names' => array_values(array_filter(explode(',', (string) env('SIXTEC_MONITOR_QUEUES', 'default')))),
        'pending' => ['warning' => 500, 'critical' => 2000],
        // Age of the oldest waiting job, in seconds (database queue).
        'wait_seconds' => ['warning' => 300, 'critical' => 900],
        // Failed jobs in the last hour.
        'failed_per_hour' => ['warning' => 1, 'critical' => 10],
    ],

    'scheduler' => [
        // How often cron runs schedule:run. Usually 15 on shared hosting.
        'expected_minutes' => (int) env('SIXTEC_MONITOR_SCHEDULER_MINUTES', 1),
    ],

    'storage' => [
        // Disk under test (writes, reads and deletes a small file). Default:
        // filesystems.default. The test runs at most every `every_minutes`:
        // on S3/Spaces each test is three billed requests.
        'disk' => env('SIXTEC_MONITOR_DISK'),
        'every_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | CPU, memory, disk, load and the systemd services, read from /proc. Null
    | = automatic: enabled in `service` mode (VPS). On shared hosting /proc
    | shows the whole machine, shared by every customer, and the numbers
    | mislead — so it stays off.
    |
    */

    'server' => [
        'enabled' => env('SIXTEC_MONITOR_SERVER'),
        'cpu' => ['warning' => 90, 'critical' => null],
        'memory' => ['warning' => 85, 'critical' => 95],
        'disk' => ['warning' => 85, 'critical' => 95],
        // Watched systemd services; the ones not installed are ignored.
        // Stopped or failed = critical.
        'services' => [
            'nginx', 'apache2', 'caddy',
            'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm', 'php-fpm',
            'mysql', 'mariadb', 'postgresql', 'redis-server', 'redis',
            'supervisor', 'docker',
        ],
    ],

];
