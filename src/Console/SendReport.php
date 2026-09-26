<?php

namespace Sixtec\Monitor\Console;

use Illuminate\Console\Command;
use Sixtec\Monitor\PanelClient;
use Sixtec\Monitor\Report;

/**
 * One push: builds the report and sends it to the panel. This is what the
 * scheduler runs in `scheduler` mode, and what to use to test an install.
 * With `--show`, prints the report without sending it.
 */
class SendReport extends Command
{
    protected $signature = 'sixtec:monitor:send
                            {--show : Only print the report, do not send it}
                            {--scheduled : No output (used by the scheduler)}';

    protected $description = 'Build the health report and send it to the Sixtec monitor panel';

    public function handle(Report $report, PanelClient $panel): int
    {
        $data = $report->build();

        if ($this->option('show')) {
            $this->table(['Component', 'Status', 'Message'], array_map(
                fn (array $check): array => [$check['label'], $check['status'], (string) $check['message']],
                $data['checks'],
            ));

            return self::SUCCESS;
        }

        if (! $panel->configured()) {
            $this->option('scheduled') || $this->warn('SIXTEC_MONITOR_KEY is not set: nothing sent. Run php artisan sixtec:monitor:install.');

            return self::SUCCESS;
        }

        $result = $panel->send($data);

        if ($this->option('scheduled')) {
            return self::SUCCESS;
        }

        if (! $result['sent']) {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }

        $this->info('Report sent. Panel state: '.($result['state'] ?? $data['status']).'.');

        return self::SUCCESS;
    }
}
