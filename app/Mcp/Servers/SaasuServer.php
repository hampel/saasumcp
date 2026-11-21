<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Read-only lookups in Saasu accounting files, over stdio.
 *
 * Every tool here reads, and the class deliberately has no way to do anything else. Saasu's own
 * permissions are the second guard: a login with read access to every area and write access to
 * none passes every probe in app:validate (measured 2026-11-21), so a deployment need not give it
 * more. A write would be a new tool marked destructive, not a flag on one of these.
 */
#[Name('Saasu accounting lookups')]
#[Instructions(<<<'TEXT'
    Read-only lookups in Saasu accounting files: invoices (sales and purchases) with their lines, payments,
    contacts, the chart of accounts, tax codes, inventory items and bank balances. Nothing here changes a file.

    Start with list-files; every other tool takes a file key from it. A Saasu file is one business's books.

    EVERY REQUEST IS RATIONED. Saasu allows each file a daily quota, and exceeding it blocks the whole file for 24
    hours - for every system using it, including the one that books its sales each morning. This server keeps its
    own, much lower, limit per file over any 24 hours and refuses a call that would pass it; every answer's `budget`
    says what is left. So:

    - Ask for one record by id (get-invoice, get-payment, ...) rather than listing to find it, when you have the id.
    - Lists need a date range or a filter, and fetch one page of 100 unless asked for more. Saasu never says how
      many records match, so `more_exist` is all there is: narrow the filter rather than paging on.
    - list-accounts and list-tax-codes are kept for a day and usually cost nothing. Use them to name the account and
      tax code ids on invoice lines, instead of asking for accounts one at a time.
    - If a call is refused for budget, stop and say what you could not look up. Do not retry it.

    Transaction dates are calendar dates in the books: read the date and ignore the time Saasu attaches.

    When a tool returns an error, read it: it says whether the file is unknown, the login is refused or lacks a
    permission, the record does not exist, or the budget is spent - different problems with different fixes.
    TEXT)]
class SaasuServer extends Server
{
    protected array $tools = [
        ListFiles::class,
        FileAccess::class,
        GetInvoice::class,
        GetPayment::class,
        GetContact::class,
        GetAccount::class,
        GetItem::class,
        ListInvoices::class,
        ListPayments::class,
        FindContacts::class,
        FindItems::class,
        ListAccounts::class,
        ListTaxCodes::class,
        BankBalances::class,
    ];

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
