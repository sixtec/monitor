<?php

namespace Sixtec\Monitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sixtec\Monitor\PanelClient;
use Sixtec\Monitor\Report;

/**
 * Installs the agent: writes the key and the mode to .env, sends a test
 * report and turns on continuous pushing.
 *
 * - With systemd (VPS), generates the `sixtec-monitor-<app>` service. Running
 *   as root, installs and starts it; otherwise writes the unit to storage/
 *   and prints the three sudo commands.
 * - Without systemd (shared hosting), uses the app's scheduler, which cron
 *   already runs: nothing to install on the system.
 */
class InstallAgent extends Command
{
    protected $signature = 'sixtec:monitor:install
                            {--key= : Push key generated in the panel}
                            {--mode= : service or scheduler (default: detects systemd)}
                            {--cron-minutes=1 : In scheduler mode, how often cron runs}
                            {--skip-test : Do not send the test report}';

    protected $description = 'Install the Sixtec Monitor agent (systemd service or scheduler)';

    public function handle(Report $report, PanelClient $panel): int
    {
        $key = (string) ($this->option('key') ?: config('sixtec-monitor.key') ?: $this->secret('Push key (panel › Monitores › the system › "Como o agente envia")'));

        if (! str_starts_with($key, 'sxs_')) {
            $this->error('The push key starts with sxs_. Generate one in the panel.');

            return self::FAILURE;
        }

        $mode = (string) ($this->option('mode') ?: ($this->hasSystemd() ? 'service' : 'scheduler'));

        if (! in_array($mode, ['service', 'scheduler'], true)) {
            $this->error('Invalid mode: use service or scheduler.');

            return self::FAILURE;
        }

        $cronMinutes = max(1, (int) $this->option('cron-minutes'));

        config(['sixtec-monitor.key' => $key, 'sixtec-monitor.mode' => $mode]);

        if (! $this->option('skip-test') && ! $this->test($report, $panel)) {
            return self::FAILURE;
        }

        $this->writeEnv([
            'SIXTEC_MONITOR_KEY' => $key,
            'SIXTEC_MONITOR_MODE' => $mode,
            ...($mode === 'scheduler' && $cronMinutes > 1 ? ['SIXTEC_MONITOR_SCHEDULER_MINUTES' => (string) $cronMinutes] : []),
        ]);

        $mode === 'service' ? $this->installService() : $this->explainScheduler($cronMinutes);

        if (app()->configurationIsCached()) {
            $this->warn('Config is cached: run php artisan config:cache so the app reads the new key.');
        }

        return self::SUCCESS;
    }

    private function test(Report $report, PanelClient $panel): bool
    {
        $this->info('Building the report and sending it to the panel…');

        $data = $report->build();
        $this->table(['Component', 'Status', 'Message'], array_map(
            fn (array $check): array => [$check['label'], $check['status'], (string) $check['message']],
            $data['checks'],
        ));

        $result = $panel->send($data);

        if (! $result['sent']) {
            $this->error((string) $result['error']);

            return false;
        }

        $this->info('Test report accepted. Panel state: '.($result['state'] ?? $data['status']).'.');

        return true;
    }

    private function installService(): void
    {
        $name = 'sixtec-monitor-'.(Str::slug((string) config('app.name')) ?: 'app');
        $unit = $this->renderUnit($name);

        if ($this->isRoot()) {
            file_put_contents("/etc/systemd/system/{$name}.service", $unit);
            Process::run(['systemctl', 'daemon-reload']);
            $started = Process::run(['systemctl', 'enable', '--now', "{$name}.service"]);

            $started->successful()
                ? $this->info("Service {$name} installed and running. Follow it with: journalctl -u {$name} -f")
                : $this->error("systemd did not start the service: {$started->errorOutput()}");
        } else {
            $file = storage_path("app/sixtec-monitor/{$name}.service");
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, $unit);

            $this->info("Service written to {$file}. To install it, run as root:");
            $this->line("  sudo cp {$file} /etc/systemd/system/{$name}.service");
            $this->line('  sudo systemctl daemon-reload');
            $this->line("  sudo systemctl enable --now {$name}.service");
            $this->line("Then: journalctl -u {$name} -f");
        }

        $this->line('In the panel, set the agent interval to 1 minute.');
    }

    private function explainScheduler(int $cronMinutes): void
    {
        $this->info("Scheduler mode: the app's schedule:run sends the report — nothing to install on the system.");
        $this->line("Make sure the app's cron entry exists:");
        $this->line('  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line("In the panel, set the agent interval to {$cronMinutes} minute(s) — the cron interval.");
    }

    private function renderUnit(string $name): string
    {
        $owner = function_exists('posix_getpwuid') ? posix_getpwuid((int) fileowner(base_path())) : false;
        $group = function_exists('posix_getgrgid') ? posix_getgrgid((int) filegroup(base_path())) : false;

        return strtr((string) file_get_contents(__DIR__.'/../../stubs/sixtec-monitor.service.stub'), [
            '{{app_name}}' => (string) config('app.name'),
            '{{name}}' => $name,
            '{{user}}' => $owner['name'] ?? get_current_user(),
            '{{group}}' => $group['name'] ?? ($owner['name'] ?? get_current_user()),
            '{{path}}' => base_path(),
            '{{php}}' => PHP_BINARY,
        ]);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        $file = app()->environmentFilePath();
        $contents = is_readable($file) ? (string) file_get_contents($file) : '';

        foreach ($values as $variable => $value) {
            $line = $variable.'='.$value;
            $pattern = '/^'.preg_quote($variable, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace_callback($pattern, fn (): string => $line, $contents)
                : ltrim(rtrim($contents, "\n")."\n{$line}\n", "\n");
        }

        file_put_contents($file, $contents);
    }

    protected function hasSystemd(): bool
    {
        return is_dir('/run/systemd/system');
    }

    protected function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}
