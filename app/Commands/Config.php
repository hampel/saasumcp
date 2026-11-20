<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\FileInventory;
use App\Support\FileInventoryException;
use App\Support\SaasuFile;
use Hampel\ConsoleReport\FormatsValues;
use Hampel\ConsoleReport\ReportsSettings;
use LaravelZero\Framework\Commands\Command;

/**
 * What this installation is configured to do. Contacts nothing, and prints no credential.
 */
class Config extends Command
{
    use FormatsValues;
    use ReportsSettings;

    protected $signature = 'app:config {--only= : The section to display}';

    protected $description = 'Show application configuration';

    public function handle(FileInventory $inventory): int
    {
        $this->setReportOutput($this->getOutput());

        $this->reportSettings($this->settings($inventory), $this->option('only'));

        return self::SUCCESS;
    }

    /**
     * The environment file that was read, or where one was looked for. bootstrap/app.php records
     * the outcome of its own search; environmentFilePath() would name a file whether or not it
     * exists.
     */
    private function environmentFile(): string
    {
        $loaded = $this->laravel->bound('saasumcp.env.loaded') ? $this->laravel->make('saasumcp.env.loaded') : null;

        if (is_string($loaded)) {
            return $this->path($loaded);
        }

        $candidates = $this->laravel->bound('saasumcp.env.candidates') ? $this->laravel->make('saasumcp.env.candidates') : [];

        return is_array($candidates) && $candidates !== []
            ? '<fg=yellow>none found</> - looked in '.implode(', ', $candidates)
            : $this->notSet();
    }

    /**
     * @return array<string, array<string, string>> settings to report, by section
     */
    private function settings(FileInventory $inventory): array
    {
        $files = [];
        $error = null;

        try {
            $files = $inventory->all();
        } catch (FileInventoryException $e) {
            $error = $e->getMessage();
        }

        $section = [
            'Inventory Path' => $this->path($inventory->path()),
            'Files Loaded' => $error !== null ? '<fg=red>'.$error.'</>' : count($files).' file(s)',
            'Default Daily Budget' => config('saasumcp.daily_budget').' requests',
        ];

        foreach ($files as $file) {
            $section['File: '.$file->key] = $this->describe($file);
        }

        return [
            'Application' => [
                'Name' => (string) config('app.name'),
                'Version' => (string) $this->app->version(),
                'Laravel Version' => $this->app::VERSION,
                'PHP Version' => (string) phpversion(),
                'Environment File' => $this->environmentFile(),
                // an MCP client starts this process from a directory of its choosing, and a
                // compiled binary resolves its storage path from it
                'Working Directory' => $this->path(getcwd() ?: ''),
            ],

            'MCP' => [
                'Server' => 'saasu (stdio only; nothing listens on a port)',
                'Start Command' => config('app.name').' mcp:start saasu',
                'Display Timezone' => (string) config('saasumcp.timezone'),
            ],

            'State' => [
                'State Path' => $this->path((string) config('saasumcp.state_path')),
                'Throttle' => (string) config('saasu.throttle'),
            ],

            'Files' => $section,
        ];
    }

    private function describe(SaasuFile $file): string
    {
        return sprintf(
            '%s, file id %d, %d a day, login %s',
            $file->name,
            $file->fileId,
            $file->dailyBudget,
            $file->hasCredentials() ? 'set' : '<fg=red>not set</>',
        );
    }
}
