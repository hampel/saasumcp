<?php

declare(strict_types=1);

namespace App\Commands;

use App\Saasu\DailyBudget;
use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\FileInventory;
use App\Support\FileInventoryException;
use App\Support\SaasuFile;
use Closure;
use Hampel\ConsoleReport\RendersChecks;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Enum\PaymentType;
use Hampel\Saasu\Api\Filter\DateRange;
use Hampel\Saasu\Api\Filter\InvoiceFilter;
use Hampel\Saasu\Api\Filter\PaymentFilter;
use LaravelZero\Framework\Commands\Command;

/**
 * Can this machine do what the configuration says? Exercised, not described.
 *
 * The command a deployment gates on. Run it as the user the MCP client starts the server as: the
 * state directory holds cached tokens, and a check run as root reports a directory writable that
 * the server's user cannot touch.
 *
 * ONLINE, IT ASKS SAASU FOR EVERYTHING THE TOOLS NEED, one cheap request each, so that a login
 * missing a permission fails here rather than in the middle of someone's question. Saasu's
 * refusals are irregular - a login without the Purchase permission is refused even a list of
 * sales, and a payment list refuses with a 400 about a null collection - so the only reliable test
 * of a permission is the request itself. Every probe goes through the gateway and counts against
 * the file's daily budget: about a dozen requests a file.
 *
 * Exit: 0 when nothing failed, 1 when anything did. --strict makes a warning-only run exit 2.
 */
class Validate extends Command
{
    use RendersChecks;

    /** A budget more than this share spent is worth a warning. */
    private const BUDGET_WARN = 0.8;

    protected $signature = 'app:validate
                            {--offline : Skip the checks that reach Saasu}
                            {--strict : Exit 2 when a check warned and none failed, for a monitor rather than a gate}';

    protected $description = 'Check the configuration, the state directory, and that each login can read what the tools need';

    public function handle(FileInventory $inventory, Gateway $gateway, DailyBudget $budget): int
    {
        $this->setReportOutput($this->getOutput());

        // RendersChecks keeps its outcome on the command, and Laravel reuses a command object
        // within a process - so without this a second call inherits the first one's failure
        $this->checkFailed = false;
        $this->checkWarned = false;

        $this->checkSection('Runtime');
        $this->checkRuntime();

        $this->checkSection('State');
        $this->checkState();

        $this->checkSection('Files');
        $files = $this->checkInventory($inventory);

        foreach ($files as $file) {
            $this->checkSection('File: '.$file->key);
            $this->checkFile($file, $gateway, $budget);
        }

        return $this->checkExitCode((bool) $this->option('strict'));
    }

    private function checkRuntime(): void
    {
        $user = function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid())
            : (getenv('USER') ?: 'unknown');

        $user === 'root'
            ? $this->checkWarn('Running as', 'root - run as the user the MCP client starts the server as')
            : $this->checkOk('Running as', $user);

        $this->checkOk('PHP', PHP_VERSION);

        $loaded = $this->laravel->bound('saasumcp.env.loaded') ? $this->laravel->make('saasumcp.env.loaded') : null;

        is_string($loaded)
            ? $this->checkOk('Environment file', $loaded)
            : $this->checkSkip('Environment file', 'none found - every setting is a default');
    }

    private function checkState(): void
    {
        $path = (string) config('saasumcp.state_path');

        if (! config('saasumcp.state_path_set') && $this->followsWorkingDirectory()) {
            $this->checkFail('State directory', $path.' - derived from the working directory, which an MCP client chooses; set SAASUMCP_STATE_PATH. '
                .'Each directory would keep its own daily budget.');

            return;
        }

        if (! self::isAbsolute($path)) {
            $this->checkFail('State directory', $path.' - not an absolute path');

            return;
        }

        if (! is_dir($path)) {
            $parent = dirname($path);

            is_dir($parent) && is_writable($parent)
                ? $this->checkWarn('State directory', $path.' - does not exist yet; the first call creates it 0700')
                : $this->checkFail('State directory', $path.' - does not exist, and '.$parent.' is not writable');

            return;
        }

        $mode = fileperms($path) & 0777;

        if (! is_writable($path)) {
            $this->checkFail('State directory', $path.' - not writable by this user');
        } elseif (($mode & 0077) !== 0 && DIRECTORY_SEPARATOR !== '\\') {
            $this->checkFail('State directory', sprintf('%s - %04o; it holds cached Saasu tokens, make it 0700', $path, $mode));
        } else {
            $this->checkOk('State directory', $path);
        }
    }

    /** @return array<string, SaasuFile> */
    private function checkInventory(FileInventory $inventory): array
    {
        $path = $inventory->path();

        try {
            $files = $inventory->all();
        } catch (FileInventoryException $e) {
            $this->checkFail('File inventory', ucfirst($e->getMessage()));

            return [];
        }

        $this->checkOk('File inventory', $path.' - '.count($files).' file(s): '.implode(', ', array_keys($files)));

        $this->checkInventoryMode($path);

        $default = (int) config('saasumcp.daily_budget');

        $this->checkOk('Default daily budget', $default.' requests a file in any 24 hours');

        return $files;
    }

    /** The inventory holds every file's password, so only the server's user may read it. */
    private function checkInventoryMode(string $path): void
    {
        $stat = @stat($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->checkSkip('Inventory mode', 'not a Unix file mode on Windows; limit who can read the file in its Security properties');
        } elseif ($stat === false) {
            $this->checkSkip('Inventory mode', 'could not be read');
        } else {
            [$ok, $detail] = self::fileModeVerdict($stat['mode'], $stat['uid'], self::groupName($stat['gid']));

            $ok ? $this->checkOk('Inventory mode', $detail) : $this->checkFail('Inventory mode', $detail);
        }
    }

    private function checkFile(SaasuFile $file, Gateway $gateway, DailyBudget $budget): void
    {
        $this->checkOk('Saasu file', $file->name.' - file id '.$file->fileId);

        if (! $file->hasCredentials()) {
            $this->checkFail('Login', 'not set - add username and password under ['.$file->key.'] in the file inventory');

            return;
        }

        $this->checkOk('Login', 'username and password set');

        $spent = $budget->spent($file);

        $spent >= $file->dailyBudget * self::BUDGET_WARN
            ? $this->checkWarn('Budget', sprintf('%d of %d used in the last 24 hours', $spent, $file->dailyBudget))
            : $this->checkOk('Budget', sprintf('%d of %d used in the last 24 hours', $spent, $file->dailyBudget));

        if ($this->option('offline')) {
            $this->checkSkip('Saasu', 'not reached - --offline');

            return;
        }

        if (! $this->probe($gateway, $file, 'Reaches the file', static fn (Client $saasu): string => $saasu->files()->identity()->raw['TradingName'] ?? 'yes')) {
            return; // every other probe would fail the same way, and spend a request saying so
        }

        // one request each, a page of one; the window is wide enough to be a real list and narrow
        // enough to be cheap for Saasu to answer
        $recent = DateRange::between(date('Y-m-d', strtotime('-31 days')), date('Y-m-d'));

        foreach ($this->probes($recent) as $label => $probe) {
            $this->probe($gateway, $file, $label, $probe);
        }

        $this->checkOk('Requests', sprintf('%d of %d now used in the last 24 hours', $budget->spent($file), $file->dailyBudget));
    }

    /**
     * Every permission a tool needs, as the request that tool makes.
     *
     * @return array<string, Closure(Client): mixed>
     */
    private function probes(DateRange $recent): array
    {
        return [
            'Lists sales' => static fn (Client $s): int => count($s->invoices()->list(InvoiceFilter::sales()->datedBetween($recent), 1, 1)),
            'Lists purchases' => static fn (Client $s): int => count($s->invoices()->list(InvoiceFilter::purchases()->datedBetween($recent), 1, 1)),
            'Lists sale payments' => static fn (Client $s): int => count($s->payments()->list(PaymentFilter::all()->ofType(PaymentType::SalePayment)->paidBetween($recent), 1, 1)),
            'Lists purchase payments' => static fn (Client $s): int => count($s->payments()->list(PaymentFilter::all()->ofType(PaymentType::PurchasePayment)->paidBetween($recent), 1, 1)),
            'Lists contacts' => static fn (Client $s): int => count($s->contacts()->list(null, 1, 1)),
            'Lists accounts' => static fn (Client $s): int => count($s->accounts()->list(null, 1, 1)),
            'Lists tax codes' => static fn (Client $s): int => count($s->taxCodes()->list(null, 1, 1)),
            'Lists items' => static fn (Client $s): int => count($s->items()->list(null, 1, 1)),
            'Reads bank balances' => static fn (Client $s): int => count($s->accounts()->bankBalances()),
        ];
    }

    /** @param  Closure(Client): mixed  $probe */
    private function probe(Gateway $gateway, SaasuFile $file, string $label, Closure $probe): bool
    {
        try {
            $answer = $gateway->call($file, 1, $probe);
        } catch (SaasuCallException $e) {
            $this->checkFail($label, $e->getMessage());

            return false;
        }

        $this->checkOk($label, is_int($answer) ? 'yes' : (is_string($answer) ? $answer : 'yes'));

        return true;
    }

    /**
     * Whether a file holding passwords is safe at this mode: 0600, or root-owned and readable by
     * one group - how a deployment renders it, so that the server cannot rewrite its own
     * configuration and the user it runs as reads it through the group. From xfmcp.
     *
     * @return array{0: bool, 1: string}
     */
    public static function fileModeVerdict(int $mode, int $owner, ?string $group = null): array
    {
        $perms = $mode & 0777;
        $shown = sprintf('%04o', $perms);

        if (($perms & 0007) !== 0) {
            return [false, $shown.' - readable by any user on this machine; it holds Saasu passwords, make it 0600'];
        }

        if (($perms & 0070) === 0) {
            return [true, $shown];
        }

        if ($owner === 0 && ($perms & 0070) === 0040) {
            return [true, $shown.' - owned by root, readable by group '.($group ?? 'of the file').' and no one else'];
        }

        return [false, $shown.' - readable beyond its owner; it holds Saasu passwords, make it 0600, or root-owned and 0640 for a group of the user who runs it'];
    }

    private static function groupName(int $gid): ?string
    {
        if (! function_exists('posix_getgrgid')) {
            return null;
        }

        $group = @posix_getgrgid($gid);

        return is_array($group) ? $group['name'] : null;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function followsWorkingDirectory(): bool
    {
        return $this->laravel->bound('saasumcp.storage.follows_cwd')
            && $this->laravel->make('saasumcp.storage.follows_cwd') === true;
    }
}
