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

    Account ids on the lines are named by list-accounts, tax codes by list-tax-codes; both are usually free.

    Ids come from list-journals: `TransactionId` there. One request, plus a login if none is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetJournal extends GetRecord
{
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
