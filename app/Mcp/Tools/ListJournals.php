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
    List manual journals dated in a range, optionally only those for one contact, or only those with a line on one
    account.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5). Saasu does not say how many journals match, so when a page comes back full `more_exist` is true.

    `from` and `to` are the journal date, inclusive, YYYY-MM-DD, and are required. A listed journal has no lines and
    no `Notes`: get-journal with its `TransactionId` has them.

    `account_id` is not a filter Saasu has, so this server reads every journal in the range, ONE REQUEST EACH, and
    keeps those with a line on that account, returned whole, lines included. It refuses a range holding more than
    25 journals - having spent the list request finding that out - so narrow the range first: a quarter rather than
    a year, say. Bank transfers are not journals, and Saasu's API documents no way to read them.
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
            'account_id' => $schema->integer()->min(1)->description(sprintf('Only journals with a line on this account, by its Saasu id. Costs one request per journal in the range, at most %d.', self::MAX_READS)),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $range = $this->range($request, 'from', 'to');
        $contact = $this->id($request->get('contact_id'), 'contact_id', required: false);
        $account = $this->id($request->get('account_id'), 'account_id', required: false);
        $pages = $this->pageCount($request->get('pages'));

        if ($range === null) {
            throw new SaasuCallException('`from` and `to` are required: a journal list needs a date range.');
        }

        $filter = JournalFilter::all()->datedBetween(DateRange::between(...$range));

        if ($contact !== null) {
            $filter = $filter->contact($contact);
        }

        if ($account === null) {
            $result = $gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
                static fn (int $page) => $saasu->journals()->list($filter, $page),
                $pages,
            ));

            return [
                'count' => $result['count'],
                'more_exist' => $result['more_exist'],
                'note' => $this->moreNote($result),
                'dates' => Present::dateNote(),
                'journals' => $result['records'],
            ];
        }

        // one call, so the budget reserves the whole of it up front and the answer's count is right
        $journals = $gateway->call($file, $pages + self::MAX_READS, function (Client $saasu) use ($filter, $pages, $account): array {
            $listed = $this->pages(static fn (int $page) => $saasu->journals()->list($filter, $page), $pages);

            if ($listed['more_exist'] || $listed['count'] > self::MAX_READS) {
                throw new SaasuCallException(sprintf(
                    'The range holds %s journals, and finding those on account %d means reading each one; this call reads at most %d. Narrow the range.',
                    $listed['more_exist'] ? 'more than '.$listed['count'] : (string) $listed['count'],
                    $account,
                    self::MAX_READS,
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
            'note' => sprintf('Read all %d journals in the range; these are the ones with a line on account %d.', $journals['read'], $account),
            'dates' => Present::dateNote(),
            'journals' => $journals['matched'],
        ];
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
