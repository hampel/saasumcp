<?php

use App\Mcp\Servers\SaasuServer;
use App\Mcp\Tools\BankBalances;
use App\Mcp\Tools\FindContacts;
use App\Mcp\Tools\GetInvoice;
use App\Mcp\Tools\GetItem;
use App\Mcp\Tools\GetPayment;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListInvoices;
use App\Mcp\Tools\ListPayments;
use App\Saasu\DailyBudget;
use App\Support\FileInventory;
use Illuminate\Support\Facades\Http;

/** @return array<mixed> the structured answer of a tool call that succeeded */
function answer(string $tool, array $arguments): array
{
    $response = SaasuServer::tool($tool, $arguments);
    $response->assertHasNoErrors();

    $content = (fn () => $this->response->toArray())->call($response);

    return $content['result']['structuredContent'];
}

/** The text of a tool call that failed. */
function refusal(string $tool, array $arguments): string
{
    $response = SaasuServer::tool($tool, $arguments);
    $response->assertHasErrors();

    $content = (fn () => $this->response->toArray())->call($response);

    return $content['result']['content'][0]['text'];
}

it('lists the files without contacting Saasu, and without a login', function () {
    $answer = answer(ListFiles::class, []);

    expect($answer['count'])->toBe(2)
        ->and($answer['files'][1])->toMatchArray(['file' => 'other', 'file_id' => 67890, 'daily_budget' => 20, 'used_last_24h' => 0])
        ->and(json_encode($answer))->not->toContain('api@acme')->not->toContain(PASSWORD)
        ->and(Http::recorded())->toBeEmpty();
});

it('reads an invoice as Saasu sent it, without its links', function () {
    fakeSaasu(['Invoice/*' => 'invoice']);

    $answer = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 5093684]);

    expect($answer['invoice']['TransactionId'])->toBe(5093684)
        ->and($answer['invoice']['LineItems'][0]['TaxCode'])->toBe('G1')
        ->and(json_encode($answer['invoice']))->not->toContain('_links')
        ->and($answer['file'])->toBe(['file' => 'acme', 'name' => 'Acme Example Pty Ltd'])
        ->and($answer['dates'])->toContain('calendar dates');

    $sent = dataRequests();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->url())->toContain('Invoice/5093684')->toContain('FileId=12345');
});

it('counts the login and the request against the budget, and the next call only the request', function () {
    fakeSaasu(['Invoice/*' => 'invoice']);

    $first = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);
    $second = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);

    expect($first['budget'])->toBe(['requests_this_call' => 2, 'used_last_24h' => 2, 'daily_budget' => 500])
        ->and($second['budget'])->toBe(['requests_this_call' => 1, 'used_last_24h' => 3, 'daily_budget' => 500]);
});

it('refuses a call that would pass the budget, without asking Saasu', function () {
    fakeSaasu(['Invoice/*' => 'invoice']);

    $file = app(FileInventory::class)->get('other');
    app(DailyBudget::class)->reserve($file, 18);

    $text = refusal(GetInvoice::class, ['file' => 'other', 'invoice_id' => 1]);

    expect($text)->toContain('Refused without asking Saasu')->toContain('18 of the 20')->toContain('Do not work round it')
        ->and(Http::recorded())->toBeEmpty();
});

it('says so when a record does not exist', function () {
    fakeSaasu(['Payment/*' => [['Message' => 'not found'], 404]]);

    expect(refusal(GetPayment::class, ['file' => 'acme', 'payment_id' => 99]))->toBe('"acme" has no payment with id 99.');
});

it('says so when an item does not exist, which Saasu answers with a 400', function () {
    fakeSaasu(['Item/*' => [['Message' => 'Unable to find the item'], 400]]);

    $response = SaasuServer::tool(GetItem::class, ['file' => 'acme', 'item_id' => 99]);

    $response->assertHasErrors();
});

it('names an unknown file and the ones there are', function () {
    expect(refusal(GetInvoice::class, ['file' => 'nope', 'invoice_id' => 1]))
        ->toContain('no Saasu file called "nope"')->toContain('acme, other')->toContain('list-files');
});

it('names the missing login without showing the other half', function () {
    config(['saasumcp.credentials.acme' => ['username' => 'api@acme.example.test']]);
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345]]);

    expect(refusal(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]))
        ->toContain('SAASUMCP_ACME_PASSWORD')->not->toContain('api@acme');
});

it('turns a permission refusal into a sentence about the login', function () {
    fakeSaasu(['Invoices*' => [['Message' => 'You do not have permission'], 403]]);

    expect(refusal(ListInvoices::class, ['file' => 'acme', 'kind' => 'purchase', 'from' => '2026-11-01', 'to' => '2026-11-30']))
        ->toContain('Saasu refused the login this server uses for "acme" permission');
});

it('explains the 400 a restricted login gets for a payment list', function () {
    fakeSaasu(['Payments*' => [['Message' => 'Value cannot be null. Parameter name: collection'], 400]]);

    expect(refusal(ListPayments::class, ['file' => 'acme', 'kind' => 'purchase', 'from' => '2026-11-01', 'to' => '2026-11-30']))
        ->toContain('Value cannot be null')->toContain('null collection');
});

it('refuses to list invoices without a range or a filter, and asks Saasu nothing', function () {
    expect(refusal(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale']))->toContain('Narrow the list')
        ->and(Http::recorded())->toBeEmpty();
});

it('refuses half a range', function () {
    expect(refusal(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'from' => '2026-11-01']))
        ->toContain('Give both `from` and `to`');
});

it('refuses a date it cannot read rather than guessing at it', function () {
    expect(refusal(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'from' => '03/04/2026', 'to' => '2026-11-30']))
        ->toContain('YYYY-MM-DD');
});

it('lists sales in a range, one page, and says when no more match', function () {
    fakeSaasu(['Invoices*' => 'invoices']);

    $answer = answer(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'from' => '2026-11-01', 'to' => '2026-11-30']);

    $url = urldecode(dataRequests()[0]->url());

    expect($answer['more_exist'])->toBeFalse()
        ->and($answer['note'])->toBe('This is everything that matched.')
        ->and($answer['count'])->toBe(count($answer['invoices']))
        ->and($url)->toContain('TransactionType=S')->toContain('InvoiceFromDate=2026-11-01')->toContain('InvoiceToDate=2026-11-30')
        ->and(dataRequests())->toHaveCount(1);
});

it('fetches no more pages than asked for, and says more exist', function () {
    $full = ['Invoices' => array_fill(0, 100, saasuFixture('invoices')['Invoices'][0])];
    fakeSaasu(['Invoices*' => [$full, 200]]);

    $answer = answer(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'invoice_number' => 'INV-1', 'pages' => 2]);

    expect(dataRequests())->toHaveCount(2)
        ->and($answer['count'])->toBe(200)
        ->and($answer['more_exist'])->toBeTrue()
        ->and($answer['note'])->toContain('Saasu does not say how many');
});

it('refuses more pages than one call may fetch', function () {
    expect(refusal(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'invoice_number' => 'X', 'pages' => 6]))
        ->toContain('from 1 to 5');
});

it('finds an invoice\'s payments with a range wide enough for all of them', function () {
    fakeSaasu(['Payments*' => 'payments']);

    answer(ListPayments::class, ['file' => 'acme', 'kind' => 'purchase', 'invoice_id' => 5093684]);

    expect(urldecode(dataRequests()[0]->url()))
        ->toContain('ForInvoiceId=5093684')->toContain('PaymentFromDate=2000-01-01')->toContain('TransactionType=PP');
});

it('refuses a payment list with neither a range nor an invoice', function () {
    expect(refusal(ListPayments::class, ['file' => 'acme', 'kind' => 'sale']))->toContain('last month only');
});

it('refuses to find contacts with no filter', function () {
    expect(refusal(FindContacts::class, ['file' => 'acme']))->toContain('Give at least one')
        ->and(Http::recorded())->toBeEmpty();
});

it('finds contacts by email', function () {
    fakeSaasu(['Contacts*' => 'contacts']);

    $answer = answer(FindContacts::class, ['file' => 'acme', 'email' => 'joe@example.com']);

    expect($answer['count'])->toBeGreaterThan(0)
        ->and(urldecode(dataRequests()[0]->url()))->toContain('Email=joe@example.com');
});

it('keeps the chart of accounts for a day, and refetches it when asked', function () {
    fakeSaasu(['Accounts*' => 'accounts']);

    $first = answer(ListAccounts::class, ['file' => 'acme']);
    $second = answer(ListAccounts::class, ['file' => 'acme']);

    expect(dataRequests())->toHaveCount(1)
        ->and($second['accounts'])->toBe($first['accounts'])
        ->and($second['budget']['requests_this_call'])->toBe(0)
        ->and(urldecode(dataRequests()[0]->url()))->toContain('IncludeBuiltIn=true');

    answer(ListAccounts::class, ['file' => 'acme', 'refresh' => true]);

    expect(dataRequests())->toHaveCount(2);
});

it('keeps a cached list per file', function () {
    fakeSaasu(['Accounts*' => 'accounts']);

    answer(ListAccounts::class, ['file' => 'acme']);
    answer(ListAccounts::class, ['file' => 'other']);

    expect(dataRequests())->toHaveCount(2);
});

it('reads bank balances', function () {
    fakeSaasu(['Accounts/BankAccountBalances*' => 'bank-balances']);

    $answer = answer(BankBalances::class, ['file' => 'acme']);

    expect($answer['count'])->toBeGreaterThan(0)
        ->and($answer['balances'][0])->toHaveKey('Balance');
});

it('never puts the password in a request it was not meant for', function () {
    fakeSaasu(['Invoice/*' => 'invoice']);

    answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);

    foreach (dataRequests() as $request) {
        expect($request->url().json_encode($request->headers()))->not->toContain(PASSWORD);
    }
});
