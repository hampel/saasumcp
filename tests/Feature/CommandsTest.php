<?php

use App\Commands\Validate;
use App\Support\FileInventory;
use Symfony\Component\Console\Exception\CommandNotFoundException;

function fakeEverySaasuProbe(array $overrides = []): void
{
    fakeSaasu(array_merge([
        'FileIdentity*' => 'file-identity',
        'Invoices*' => 'invoices',
        'Payments*' => 'payments',
        'Contacts*' => 'contacts',
        'Accounts/BankAccountBalances*' => 'bank-balances',
        'Accounts*' => 'accounts',
        'TaxCodes*' => 'tax-codes',
        'Items*' => 'items',
    ], $overrides));
}

it('validates offline without asking Saasu', function () {
    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('File: acme')->toContain('username and password set')->toContain('not reached - --offline')
        ->and(Http::recorded())->toBeEmpty();
});

it('fails a file with no login', function () {
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345, 'username' => null, 'password' => null]]);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('add username and password under [acme]');
});

it('fails an inventory others can read, since it holds the passwords', function () {
    $path = sys_get_temp_dir().'/saasumcp-files-'.bin2hex(random_bytes(4)).'.toml';
    file_put_contents($path, "[acme]\nname = \"Acme\"\nfile_id = 1\nusername = \"u\"\npassword = \"p\"\n");
    chmod($path, 0644);
    config(['saasumcp.files_path' => $path]);
    app()->forgetInstance(FileInventory::class);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    chmod($path, 0600);
    [$exitAfter, $outputAfter] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toMatch('/\[fail\] Inventory mode\s+0644/')
        ->and($exitAfter)->toBe(0)->and($outputAfter)->toMatch('/\[ ok \] Inventory mode\s+0600/');
})->skipOnWindows();

it('asks Saasu for everything the tools need, and counts it against the budget', function () {
    fakeEverySaasuProbe();
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/Reaches the file\s+Test File/')->toContain('Lists purchases')->toContain('Lists sale payments')->toContain('Reads bank balances')
        ->and(dataRequests())->toHaveCount(10)
        ->and($output)->toContain('11 of 500 now used');
});

it('fails the permission a login lacks, and goes on to the rest', function () {
    fakeEverySaasuProbe(['Payments*' => [['Message' => 'Value cannot be null. Parameter name: collection'], 400]]);
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)
        ->and($output)->toMatch('/\[fail\] Lists sale payments/')
        ->and($output)->toMatch('/\[ ok \] Lists tax codes/');
});

it('stops probing a file its login cannot reach', function () {
    fakeSaasu(['FileIdentity*' => [[], 401]]);
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)
        ->and($output)->toContain('refused it file 12345')->toContain('not on that file')
        ->and(dataRequests())->toHaveCount(1);
});

it('says the password is wrong when the token is refused, and sends nothing more', function () {
    fakeSaasu(['authorisation/*' => [['error' => 'invalid_credentials', 'error_description' => 'Invalid username or password'], 401]]);
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)
        ->and($output)->toContain('username or password is wrong')->toContain('invalid_credentials')
        ->and(sentRequests())->toHaveCount(1);
});

it('fails a state directory that follows the working directory', function () {
    config(['saasumcp.state_path_set' => false]);
    app()->instance('saasumcp.storage.follows_cwd', true);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('set SAASUMCP_STATE_PATH');
});

it('judges a file of passwords by its mode', function (int $mode, int $owner, bool $ok) {
    expect(Validate::fileModeVerdict($mode, $owner, 'saasumcp')[0])->toBe($ok);
})->with([
    'owner only' => [0600, 1000, true],
    'root, group read' => [0640, 0, true],
    'world readable' => [0644, 1000, false],
    'group read, not root' => [0640, 1000, false],
]);

it('shows the configuration without a credential', function () {
    [$exit, $output] = runCommand('app:config');

    expect($exit)->toBe(0)
        ->and($output)->toContain('File: acme')->toContain('login set')
        ->and($output)->not->toContain(PASSWORD)->not->toContain('api@acme');
});

it('fails a mistyped command rather than printing the list', function () {
    expect(fn () => runCommand('app:validte'))->toThrow(CommandNotFoundException::class);
});
