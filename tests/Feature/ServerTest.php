<?php

use App\Mcp\Servers\SaasuServer;
use App\Mcp\Tools\BankBalances;
use App\Mcp\Tools\FileAccess;
use App\Mcp\Tools\FindContacts;
use App\Mcp\Tools\FindItems;
use App\Mcp\Tools\GetAccount;
use App\Mcp\Tools\GetContact;
use App\Mcp\Tools\GetInvoice;
use App\Mcp\Tools\GetItem;
use App\Mcp\Tools\GetPayment;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListInvoices;
use App\Mcp\Tools\ListPayments;
use App\Mcp\Tools\ListTaxCodes;
use Laravel\Mcp\Facades\Mcp;

const TOOLS = [
    ListFiles::class, FileAccess::class, GetInvoice::class, GetPayment::class, GetContact::class, GetAccount::class,
    GetItem::class, ListInvoices::class, ListPayments::class, FindContacts::class, FindItems::class,
    ListAccounts::class, ListTaxCodes::class, BankBalances::class,
];

it('registers every tool', function () {
    SaasuServer::tools()->assertRegistered(TOOLS);
});

it('is registered as a local server and as nothing else', function () {
    expect(Mcp::getLocalServer('saasu'))->not->toBeNull()
        ->and(array_keys(Mcp::servers()))->toBe(['saasu']);
});

it('marks every tool read-only, and never destructive', function (string $class) {
    expect(app($class)->toArray()['annotations'])
        ->toMatchArray(['readOnlyHint' => true])
        ->not->toHaveKey('destructiveHint');
})->with(TOOLS);

it('gives every tool a description that is more than its name', function (string $class) {
    expect(strlen(app($class)->description()))->toBeGreaterThan(150);
})->with(TOOLS);

it('says in every tool that costs requests what it costs', function (string $class) {
    expect(app($class)->description())->toMatch('/request/i');
})->with(array_values(array_diff(TOOLS, [ListFiles::class])));
