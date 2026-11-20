<?php

use App\Saasu\DailyBudget;
use App\Support\FileInventory;
use App\Support\FileInventoryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses()->beforeEach(function () {
    // No test may reach the network. An inventory on a developer's machine names real files with
    // real logins, so a fake that failed to intercept would be a real request against a quota.
    Http::preventStrayRequests();

    $state = sys_get_temp_dir().'/saasumcp-test-'.getmypid().'-'.bin2hex(random_bytes(4));

    config([
        'saasumcp.timezone' => 'UTC',
        'saasumcp.state_path' => $state,
        'saasumcp.state_path_set' => true,
        // a fixture, not a location: nothing parses it, useFiles() supplies the files
        'saasumcp.files_path' => '/nonexistent/files.toml',
        'saasumcp.daily_budget' => 500,
        'saasumcp.credentials' => [
            'acme' => ['username' => 'api@acme.example.test', 'password' => PASSWORD],
            'other' => ['username' => 'api@other.example.test', 'password' => PASSWORD],
        ],
        // tokens, throttle slots, budgets and cached lists in memory, never on disk
        'cache.stores.saasumcp' => ['driver' => 'array', 'serialize' => true],
    ]);

    app('cache')->forgetDriver('saasumcp');
    app()->forgetInstance(DailyBudget::class);

    useFiles([
        'acme' => ['name' => 'Acme Example Pty Ltd', 'file_id' => 12345],
        'other' => ['name' => 'Other Example Pty Ltd', 'file_id' => 67890, 'daily_budget' => 20],
    ]);
})->in('Feature');

const PASSWORD = 'password-that-must-never-be-shown';

/**
 * Bind an inventory that answers from an array instead of reading a file.
 *
 * @param  array<string, mixed>|FileInventoryException  $files
 */
function useFiles(array|FileInventoryException $files): void
{
    app()->instance(FileInventory::class, new class($files) extends FileInventory
    {
        public function __construct(private array|FileInventoryException $fixture) {}

        public function raw(): array
        {
            if ($this->fixture instanceof FileInventoryException) {
                throw $this->fixture;
            }

            return $this->fixture;
        }
    });
}

/** @return array<mixed> */
function saasuFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fake Saasu. Keys are paths after api.saasu.com/, values a fixture name, a [fixture|body, status]
 * pair, or a ready response. A login is always faked, first, as the package's README requires.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeSaasu(array $routes): void
{
    $fakes = ['api.saasu.com/authorisation/*' => Http::response(saasuFixture('token'))];

    foreach ($routes as $path => $answer) {
        $fakes['api.saasu.com/'.$path] = match (true) {
            is_string($answer) => Http::response(saasuFixture($answer)),
            is_array($answer) => Http::response(is_string($answer[0]) ? saasuFixture($answer[0]) : $answer[0], $answer[1]),
            default => $answer,
        };
    }

    Http::fake($fakes);
}

/** @return list<Request> every request that reached the (faked) wire */
function sentRequests(): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->all();
}

/** @return list<Request> the requests that were not a login */
function dataRequests(): array
{
    return array_values(array_filter(sentRequests(), fn (Request $r): bool => ! str_contains($r->url(), '/authorisation/')));
}

/**
 * Run a command and return its exit code and everything it printed.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}
