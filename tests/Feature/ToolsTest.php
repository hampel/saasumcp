<?php

use App\Mcp\Servers\SaasuServer;
use App\Mcp\Tools\BankBalances;
use App\Mcp\Tools\FindContacts;
use App\Mcp\Tools\GetInvoice;
use App\Mcp\Tools\GetItem;
use App\Mcp\Tools\GetJournal;
use App\Mcp\Tools\GetPayment;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListInvoices;
use App\Mcp\Tools\ListJournals;
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
    fakeSaasu(['Invoice/*' => 'invoice', 'TaxCodes*' => 'tax-codes']);

    $answer = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 5093684]);

    expect($answer['invoice']['TransactionId'])->toBe(5093684)
        ->and($answer['invoice']['LineItems'][0]['TaxCode'])->toBe('G1')
        ->and(json_encode($answer['invoice']))->not->toContain('_links')
        ->and($answer['file'])->toBe(['file' => 'acme', 'name' => 'Acme Example Pty Ltd'])
        ->and($answer['dates'])->toContain('calendar dates');

    $sent = dataRequests();
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->url())->toContain('Invoice/5093684')->toContain('FileId=12345')
        ->and($sent[1]->url())->toContain('TaxCodes');
});

it('counts the login and every request against the budget, and the next call only the request', function () {
    fakeSaasu(['Invoice/*' => 'invoice', 'TaxCodes*' => 'tax-codes']);

    $first = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);
    $second = answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);

    // the login, the invoice and the tax codes it names; then the invoice alone, the codes kept
    expect($first['budget'])->toBe(['requests_this_call' => 3, 'used_last_24h' => 3, 'daily_budget' => 500])
        ->and($second['budget'])->toBe(['requests_this_call' => 1, 'used_last_24h' => 4, 'daily_budget' => 500]);
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

it('says a file has no login, and sends nothing', function () {
    useFiles(['acme' => ['name' => 'Acme', 'file_id' => 12345, 'username' => null, 'password' => null]]);

    expect(refusal(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]))
        ->toContain('has no login configured')->toContain('no username and password')
        ->and(Http::recorded())->toBeEmpty();
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
    fakeSaasu(['Invoice/*' => 'invoice', 'TaxCodes*' => 'tax-codes']);

    answer(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1]);

    foreach (dataRequests() as $request) {
        expect($request->url().json_encode($request->headers()))->not->toContain(PASSWORD);
    }
});

it('shows a journal with every line, less its links', function () {
    fakeSaasu(['Journal/*' => 'journal', 'Accounts*' => 'accounts', 'TaxCodes*' => 'tax-codes']);

    $answer = answer(GetJournal::class, ['file' => 'acme', 'journal_id' => 5093684]);

    expect($answer['journal'])->toHaveKeys(['Currency', 'FxRate', 'AutoPopulateFxRate', 'Notes', 'Tags', 'CreatedDateUtc'])
        ->and($answer['journal']['Items'][0])->toEqual(['Type' => 'Credit', 'AccountId' => 321, 'TaxCode' => 'G1', 'Amount' => 10.0])
        ->and($answer['journal'])->not->toHaveKey('_links');
});

it('refuses a journal list without a range, and asks Saasu nothing', function () {
    expect(refusal(ListJournals::class, ['file' => 'acme']))->toContain('needs a date range')
        ->and(Http::recorded())->toBeEmpty();
});

it('lists journals in a range, for a contact', function () {
    fakeSaasu(['Journals*' => 'journals']);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-11-01', 'to' => '2026-11-21', 'contact_id' => 987]);

    expect($answer['count'])->toBe(count($answer['journals']))
        ->and(urldecode(dataRequests()[0]->url()))->toContain('FromDate=2025-11-01')->toContain('ToDate=2026-11-21')->toContain('JournalContactId=987')
        ->and(dataRequests())->toHaveCount(1);
});

it('reads each journal in the range to find those on an account, and keeps only those', function () {
    $listed = saasuFixture('journals');
    $listed['Journals'] = [['TransactionId' => 1] + $listed['Journals'][0], ['TransactionId' => 2] + $listed['Journals'][0]];
    $on = saasuFixture('journal');
    $off = saasuFixture('journal');
    $off['Items'][0]['AccountId'] = 999;

    fakeSaasu([
        'Journals*' => [$listed, 200],
        'Journal/1*' => [$on, 200],
        'Journal/2*' => [$off, 200],
    ]);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2026-01-01', 'to' => '2026-03-31', 'account_id' => 321]);

    expect(dataRequests())->toHaveCount(3)
        ->and($answer['count'])->toBe(1)
        ->and($answer['journals'][0]['Items'][0]['AccountId'])->toBe(321)
        ->and($answer['note'])->toContain('Read all 2 journals')
        ->and($answer['budget']['requests_this_call'])->toBe(4);
});

it('refuses to read more journals one by one than a call allows', function () {
    $listed = ['Journals' => array_map(
        fn (int $id): array => ['TransactionId' => $id] + saasuFixture('journals')['Journals'][0],
        range(1, ListJournals::MAX_READS + 1),
    )];
    fakeSaasu(['Journals*' => [$listed, 200]]);

    expect(refusal(ListJournals::class, ['file' => 'acme', 'from' => '2026-01-01', 'to' => '2026-12-31', 'account_id' => 321]))
        ->toContain('holds 26 journals')->toContain('Give `currency` or `text`, or narrow the range')
        ->and(dataRequests())->toHaveCount(1);
});

/** A journal list of these ids and currencies, each otherwise the fixture's summary. */
function journalSummaries(array $currencies): array
{
    $rows = [];

    foreach ($currencies as $id => $currency) {
        $rows[] = ['TransactionId' => $id, 'Currency' => $currency] + saasuFixture('journals')['Journals'][0];
    }

    return ['Journals' => $rows];
}

it('keeps the listed journals in one currency, and asks Saasu nothing more', function () {
    fakeSaasu(['Journals*' => [journalSummaries([1 => 'USD', 2 => 'AUD', 3 => 'usd']), 200]]);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'currency' => 'usd']);

    expect(array_column($answer['journals'], 'TransactionId'))->toBe([1, 3])
        ->and($answer['count'])->toBe(2)
        ->and(dataRequests())->toHaveCount(1)
        ->and(urldecode(dataRequests()[0]->url()))->not->toContain('Currency');
});

it('says journals not fetched may hold more in the currency', function () {
    fakeSaasu(['Journals*' => [journalSummaries(array_fill_keys(range(1, 100), 'AUD')), 200]]);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'currency' => 'USD']);

    expect($answer['count'])->toBe(0)
        ->and($answer['more_exist'])->toBeTrue()
        ->and($answer['note'])->toContain('may include more that match');
});

it('reads only the journals in the currency to find those on an account', function () {
    $on = saasuFixture('journal');

    fakeSaasu([
        'Journals*' => [journalSummaries([1 => 'USD'] + array_fill_keys(range(2, 40), 'AUD')), 200],
        'Journal/1*' => [$on, 200],
    ]);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'currency' => 'USD', 'account_id' => 321]);

    expect(dataRequests())->toHaveCount(2)
        ->and($answer['count'])->toBe(1)
        ->and($answer['note'])->toContain('Read all 1 journals in the range in USD');
});

it('refuses to look for an account in a list that did not come back whole', function () {
    fakeSaasu(['Journals*' => [journalSummaries(array_fill_keys(range(1, 100), 'AUD')), 200]]);

    expect(refusal(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'currency' => 'USD', 'account_id' => 321]))
        ->toContain('more than the 100 journals listed')->toContain('more `pages`')
        ->and(dataRequests())->toHaveCount(1);
});

it('refuses a currency that is not a three-letter code', function () {
    expect(refusal(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'currency' => 'dollars']))
        ->toContain('three-letter')
        ->and(Http::recorded())->toBeEmpty();
});

/** A chart of accounts holding these, each otherwise the fixture's first account. */
function chartOf(array $accounts): array
{
    $rows = [];

    foreach ($accounts as $id => $name) {
        $rows[] = ['Id' => $id, 'Name' => $name] + saasuFixture('accounts')['Accounts'][0];
    }

    return ['Accounts' => $rows];
}

it('names the accounts and tax codes on a journal\'s lines, beside the record', function () {
    fakeSaasu(['Journal/*' => 'journal', 'Accounts*' => [chartOf([321 => 'PayPal USD']), 200], 'TaxCodes*' => 'tax-codes']);

    $answer = answer(GetJournal::class, ['file' => 'acme', 'journal_id' => 5093684]);

    expect($answer['names']['accounts'])->toEqual(['321' => ['Name' => 'PayPal USD', 'AccountType' => 'Income', 'Currency' => 'AUD', 'IsBankAccount' => false, 'IsActive' => true]])
        ->and($answer['names']['tax_codes'])->toEqual(['G1' => ['Name' => 'Sale Inc GST', 'Rate' => 0.1, 'IsActive' => true]])
        ->and($answer['journal']['Items'][0])->not->toHaveKey('Name');
});

it('gives an account the chart does not hold as null, and says so', function () {
    fakeSaasu(['Journal/*' => 'journal', 'Accounts*' => 'accounts', 'TaxCodes*' => 'tax-codes']);

    $answer = answer(GetJournal::class, ['file' => 'acme', 'journal_id' => 5093684]);

    expect($answer['names']['accounts'])->toBe(['321' => null])
        ->and($answer['names']['note'])->toContain('not in the chart of accounts');
});

it('names from the kept lists, so a second record costs one request', function () {
    fakeSaasu(['Journal/*' => 'journal', 'Payment/*' => 'payment', 'Accounts*' => [chartOf([321 => 'PayPal USD', 1858 => 'Bank']), 200], 'TaxCodes*' => 'tax-codes']);

    answer(GetJournal::class, ['file' => 'acme', 'journal_id' => 1]);
    $payment = answer(GetPayment::class, ['file' => 'acme', 'payment_id' => 324]);

    expect($payment['names']['accounts'])->toHaveKey('1858')
        ->and($payment['names']['accounts']['1858']['Name'])->toBe('Bank')
        ->and($payment['budget']['requests_this_call'])->toBe(1);
});

it('still answers with the record when the budget leaves no room to name it', function () {
    fakeSaasu(['Journal/*' => 'journal']);

    // 20 a day: 12 used leaves room for the journal (1 and a login allowance of 2), not for a list of 5 and 2
    app(DailyBudget::class)->reserve(app(FileInventory::class)->get('other'), 12);

    $answer = answer(GetJournal::class, ['file' => 'other', 'journal_id' => 1]);

    expect($answer['journal']['TransactionId'])->toBe(5093684)
        ->and($answer['names'])->not->toHaveKey('accounts')
        ->and($answer['names']['note'])->toContain('Accounts are not named')->toContain('Tax codes are not named')
        ->and(dataRequests())->toHaveCount(1);
});

it('keeps the listed journals with some text in the summary or reference, ignoring case', function () {
    $rows = journalSummaries([1 => 'AUD', 2 => 'AUD', 3 => 'AUD'])['Journals'];
    $rows[0]['Summary'] = 'PayPal conversion USD to AUD';
    $rows[1]['Reference'] = '9XK21CONVERSION';
    fakeSaasu(['Journals*' => [['Journals' => $rows], 200]]);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'text' => 'Conversion']);

    expect(array_column($answer['journals'], 'TransactionId'))->toBe([1, 2])
        ->and(dataRequests())->toHaveCount(1);
});

it('reads only the journals with the text to find those on an account', function () {
    $rows = journalSummaries(array_fill_keys(range(1, 40), 'AUD'))['Journals'];
    $rows[0]['Summary'] = 'Conversion';
    fakeSaasu(['Journals*' => [['Journals' => $rows], 200], 'Journal/1*' => 'journal']);

    $answer = answer(ListJournals::class, ['file' => 'acme', 'from' => '2025-07-01', 'to' => '2026-06-30', 'text' => 'conversion', 'account_id' => 321]);

    expect(dataRequests())->toHaveCount(2)
        ->and($answer['note'])->toContain('Read all 1 journals in the range containing "conversion"');
});

it('keeps the listed invoices in one currency, and asks Saasu nothing more', function () {
    $rows = saasuFixture('invoices')['Invoices'];
    $rows[0]['Currency'] = 'USD';
    $rows[1]['Currency'] = 'AUD';
    fakeSaasu(['Invoices*' => [['Invoices' => $rows], 200]]);

    $answer = answer(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'from' => '2026-01-01', 'to' => '2026-12-31', 'currency' => 'usd']);

    expect($answer['count'])->toBe(1)
        ->and($answer['invoices'][0]['Currency'])->toBe('USD')
        ->and(urldecode(dataRequests()[0]->url()))->not->toContain('Currency');
});

it('lists invoices compactly when asked', function () {
    fakeSaasu(['Invoices*' => 'invoices']);

    $answer = answer(ListInvoices::class, ['file' => 'acme', 'kind' => 'sale', 'from' => '2026-01-01', 'to' => '2026-12-31', 'compact' => true]);

    expect(array_keys($answer['invoices'][0]))->toEqualCanonicalizing(ListInvoices::COMPACT)
        ->and($answer['count'])->toBe(2);
});
