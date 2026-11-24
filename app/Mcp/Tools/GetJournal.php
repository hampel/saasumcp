<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one manual journal by its Saasu id: its date, `Summary`, `Reference` and `Notes`, currency and rate
    (`Currency`, `FxRate`, `AutoPopulateFxRate`), the contact (`JournalContactId` and its names), `Tags`, the
    created and modified stamps in UTC, and in `Items` every line - `AccountId`, `Type` (Debit or Credit), `Amount`
    and `TaxCode`.

    `names`, beside the record, names each account it refers to - `Name`, `Number`, `AccountType`, `Currency` -
    and each tax code, with its `Rate`. They come from the chart of accounts and tax codes kept for 24 hours, so
    usually cost nothing; when neither is kept, fetching them costs what list-accounts and list-tax-codes would,
    about one request per hundred accounts and one for the tax codes.

    Ids come from list-journals: `TransactionId` there. One request, plus a login if none is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetJournal extends GetRecord
{
    protected function named(): bool
    {
        return true;
    }

    protected function noun(): string
    {
        return 'journal';
    }

    protected function argument(): string
    {
        return 'journal_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->journals()->find($id);
    }
}
