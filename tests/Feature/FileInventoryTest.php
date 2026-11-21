<?php

use App\Support\FileInventory;
use App\Support\FileInventoryException;

function inventoryFrom(string $toml): FileInventory
{
    $path = sys_get_temp_dir().'/saasumcp-files-'.bin2hex(random_bytes(4)).'.toml';
    file_put_contents($path, $toml);
    config(['saasumcp.files_path' => $path]);

    return new FileInventory;
}

it('reads a file, its default budget and its login', function () {
    $file = inventoryFrom("[acme]\nname = \"Acme\"\nfile_id = 12345\nusername = \"api@acme.example.test\"\npassword = \"".PASSWORD."\"\n")->get('acme');

    expect($file->fileId)->toBe(12345)
        ->and($file->dailyBudget)->toBe(500)
        ->and($file->username)->toBe('api@acme.example.test')
        ->and($file->password())->toBe(PASSWORD);
});

it('reads a file that has no login yet', function () {
    expect(inventoryFrom("[acme]\nname = \"Acme\"\nfile_id = 1\n")->get('acme')->hasCredentials())->toBeFalse();
});

it('takes a file\'s own budget over the default', function () {
    expect(inventoryFrom("[acme]\nname = \"Acme\"\nfile_id = 1\ndaily_budget = 50\n")->get('acme')->dailyBudget)->toBe(50);
});

it('refuses what it cannot use, naming why', function (string $toml, string $why) {
    expect(fn () => inventoryFrom($toml)->all())->toThrow(FileInventoryException::class, $why);
})->with([
    'a key with capitals' => ["[Acme]\nname = \"A\"\nfile_id = 1\n", 'lowercase letters and digits'],
    'an unknown setting' => ["[acme]\nname = \"A\"\nfile_id = 1\nkey = \"x\"\n", '"key" is not a setting'],
    'a password alone' => ["[acme]\nname = \"A\"\nfile_id = 1\npassword = \"x\"\n", 'has a password and no username'],
    'a username alone' => ["[acme]\nname = \"A\"\nfile_id = 1\nusername = \"x\"\n", 'has a username and no password'],
    'an empty password' => ["[acme]\nname = \"A\"\nfile_id = 1\nusername = \"x\"\npassword = \"\"\n", 'password must be text'],
    'no file id' => ["[acme]\nname = \"A\"\n", 'file_id must be a positive integer'],
    'a file id as text' => ["[acme]\nname = \"A\"\nfile_id = \"1\"\n", 'file_id must be a positive integer'],
    'a zero budget' => ["[acme]\nname = \"A\"\nfile_id = 1\ndaily_budget = 0\n", 'daily_budget must be a positive integer'],
    'one file twice' => ["[acme]\nname = \"A\"\nfile_id = 1\n[other]\nname = \"B\"\nfile_id = 1\n", 'are the same Saasu file'],
    'nothing' => ['', 'describes no file'],
    'a setting outside a table' => ["name = \"A\"\n", 'is not a file'],
]);

it('never quotes the text a parse stopped on', function () {
    try {
        inventoryFrom("[acme]\nname = \"A\"\nfile_id = 1\nusername = \"u\"\npassword = \"secret-looking-value\n")->all();
    } catch (FileInventoryException $e) {
        expect($e->getMessage())->toContain('not valid TOML')->not->toContain('secret-looking-value');

        return;
    }

    $this->fail('no exception');
});

it('says where it looked when there is no inventory', function () {
    config(['saasumcp.files_path' => '/nonexistent/files.toml']);

    expect(fn () => (new FileInventory)->all())->toThrow(FileInventoryException::class, '/nonexistent/files.toml');
});

it('reads the file again when it changes, so a new file needs no reconnect', function () {
    $inventory = inventoryFrom("[acme]\nname = \"Acme\"\nfile_id = 1\n");
    expect(array_keys($inventory->all()))->toBe(['acme']);

    file_put_contents($inventory->path(), "[acme]\nname = \"Acme\"\nfile_id = 1\n\n[other]\nname = \"Other\"\nfile_id = 2\n");

    expect(array_keys($inventory->all()))->toBe(['acme', 'other']);
});

it('ships an example that reads', function () {
    config(['saasumcp.files_path' => dirname(__DIR__, 2).'/files.toml.example']);

    expect(array_keys((new FileInventory)->all()))->toBe(['acme']);
});
