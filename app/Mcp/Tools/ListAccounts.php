<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Lookups;
use App\Support\SaasuFile;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    The file's whole chart of accounts - active and inactive, Saasu's built-in ones included - so that the
    `AccountId` on an invoice line or a payment's `PaymentAccountId` can be named.

    Each account has its `Id`, `Name`, `Number`, `AccountType` (Income, Expense, Asset, Equity, Liability,
    OtherIncome, OtherExpense, CostOfSales), `AccountLevel` (Header or Detail, with `HeaderAccountId` naming its header), `IsBankAccount`, and for a
    bank account its `Currency` and `MerchantFeeAccountId`.

    COST: kept for 24 hours and shared by every conversation, so usually nothing. Fetching it costs one request per
    hundred accounts, plus a login if none is cached. `refresh` fetches it again.
    TEXT)]
#[IsReadOnly]
class ListAccounts extends CachedList
{
    protected function listName(): string
    {
        return 'accounts';
    }

    protected function fetch(Lookups $lookups, SaasuFile $file, bool $refresh): array
    {
        return $lookups->accounts($file, $refresh);
    }
}
