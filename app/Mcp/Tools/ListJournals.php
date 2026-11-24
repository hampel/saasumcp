<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Journal;
use Hampel\Saasu\Api\Filter\DateRange;
use Hampel\Saasu\Api\Filter\JournalFilter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List manual journals dated in a range, optionally only those for one contact, in one currency, with some text in
    the summary or reference, or with a line on one account.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5). Saasu does not say how many journals match, so when a page comes back full `more_exist` is true.

    `from` and `to` are the journal date, inclusive, YYYY-MM-DD, and are required. A listed journal has no lines and
    no `Notes`: get-journal with its `TransactionId` has them.

    `currency` (USD, AUD, ...) and `text` are not filters Saasu has, but they cost nothing: the listed journals are
    sorted here. `text` keeps a journal whose `Summary` or `Reference` contains it, any part, ignoring case - a
    PayPal transaction id, say, or "conversion". `more_exist` still speaks of the whole list, so when it is true the
    journals not fetched may hold more that match.

    `account_id` is not a filter Saasu has, so this server reads every journal listed, ONE REQUEST EACH, and keeps
    those with a line on that account, returned whole, lines included. With `currency` or `text` too, only the
    journals they keep are read. It refuses to read more than 25, or when the list did not come back whole - having spent the
    list requests finding that out - so give `currency`, more `pages` or a narrower range: a quarter rather than a
    year, say. A bank transfer made in Saasu's own screens is not a journal and cannot be read through Saasu's
    API at all, so its absence here proves nothing; one booked as a journal is listed like any other.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ListJournals extends SaasuTool
{
    /** The most journals one call reads one by one to apply `account_id`. */
    public const MAX_READS = 25;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'from' => $schema->string()->description('First journal date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('Last journal date, YYYY-MM-DD, inclusive.')->required(),
            'contact_id' => $schema->integer()->min(1)->description('Only journals for this contact, by its Saasu id.'),
            'currency' => $schema->string()->description('Only journals in this currency, a three-letter code such as USD. Applied to the listed journals, so it costs nothing more.'),
            'text' => $schema->string()->description('Only journals whose Summary or Reference contains this, any part, ignoring case. Applied to the listed journals, so it costs nothing more.'),
            'account_id' => $schema->integer()->min(1)->description(sprintf('Only journals with a line on this account, by its Saasu id. Costs one request per journal in the range, at most %d.', self::MAX_READS)),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $range = $this->range($request, 'from', 'to');
        $contact = $this->id($request->get('contact_id'), 'contact_id', required: false);
        $account = $this->id($request->get('account_id'), 'account_id', required: false);
        $currency = $this->currency($request->get('currency'));
        $text = $this->text($request->get('text'), 'text');
        $pages = $this->pageCount($request->get('pages'));

        if ($range === null) {
            throw new SaasuCallException('`from` and `to` are required: a journal list needs a date range.');
        }

        $filter = JournalFilter::all()->datedBetween(DateRange::between(...$range));

        if ($contact !== null) {
            $filter = $filter->contact($contact);
        }

        if ($account === null) {
            $result = $this->keep($gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
                static fn (int $page) => $saasu->journals()->list($filter, $page),
                $pages,
            )), $currency, $text);

            return [
                'count' => $result['count'],
                'more_exist' => $result['more_exist'],
                'note' => $this->moreNote($result).(($currency !== null || $text !== null) && $result['more_exist'] ? ' Those not fetched may include more that match.' : ''),
                'dates' => Present::dateNote(),
                'journals' => $result['records'],
            ];
        }

        // one call, so the budget reserves the whole of it up front and the answer's count is right
        $journals = $gateway->call($file, $pages + self::MAX_READS, function (Client $saasu) use ($filter, $pages, $account, $currency, $text): array {
            $listed = $this->pages(static fn (int $page) => $saasu->journals()->list($filter, $page), $pages);

            if ($listed['more_exist']) {
                throw new SaasuCallException(sprintf(
                    'The range holds more than the %d journals listed, so not all of them could be checked for account %d. Ask for more `pages`, or narrow the range.',
                    $listed['count'],
                    $account,
                ));
            }

            $listed = $this->keep($listed, $currency, $text);

            if ($listed['count'] > self::MAX_READS) {
                throw new SaasuCallException(sprintf(
                    'The range holds %d journals%s, and finding those on account %d means reading each one; this call reads at most %d. %s',
                    $listed['count'],
                    $this->kept($currency, $text),
                    $account,
                    self::MAX_READS,
                    $currency !== null ? 'Narrow the range, or give `text`.' : 'Give `currency` or `text`, or narrow the range.',
                ));
            }

            $matched = [];

            foreach ($listed['records'] as $summary) {
                $journal = $saasu->journals()->find((int) $summary['TransactionId']);

                if ($journal instanceof Journal && $this->touches($journal, $account)) {
                    $matched[] = Present::record($journal);
                }
            }

            return ['matched' => $matched, 'read' => $listed['count']];
        });

        return [
            'count' => count($journals['matched']),
            'more_exist' => false,
            'note' => sprintf(
                'Read all %d journals in the range%s; these are the ones with a line on account %d.',
                $journals['read'],
                $this->kept($currency, $text),
                $account,
            ),
            'dates' => Present::dateNote(),
            'journals' => $journals['matched'],
        ];
    }

    /**
     * Keep the listed journals in one currency and with some text. Saasu's list cannot filter on
     * either; the summary carries both.
     *
     * @param  array{records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}  $result
     * @return array{records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     */
    private function keep(array $result, ?string $currency, ?string $text): array
    {
        if ($currency === null && $text === null) {
            return $result;
        }

        $result['records'] = array_values(array_filter(
            $result['records'],
            static fn (array $journal): bool => ($currency === null || strtoupper((string) ($journal['Currency'] ?? '')) === $currency)
                && ($text === null
                    || mb_stripos((string) ($journal['Summary'] ?? ''), $text) !== false
                    || mb_stripos((string) ($journal['Reference'] ?? ''), $text) !== false),
        ));
        $result['count'] = count($result['records']);

        return $result;
    }

    /** What the filters kept, as a sentence names it: " in USD containing "fee"". */
    private function kept(?string $currency, ?string $text): string
    {
        return ($currency !== null ? ' in '.$currency : '').($text !== null ? sprintf(' containing "%s"', $text) : '');
    }

    private function touches(Journal $journal, int $account): bool
    {
        foreach ($journal->items as $item) {
            if ($item->accountId === $account) {
                return true;
            }
        }

        return false;
    }
}
