<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one invoice - a sale or a purchase - with its line items, by its Saasu id.

    The answer is the record as Saasu sends it. `TransactionType` is S for a sale and P for a purchase;
    `InvoiceType` is Saasu's own label, passed on as written. Each of `LineItems` carries its account
    (`AccountId`) or inventory item (`InventoryId`), `TaxCode` and amounts, and `IsTaxInc` says whether those
    include tax. `Currency` and `FxRate` say what it was raised in. `AmountOwed`, `PaymentStatus` (P paid, U unpaid)
    and `PaymentCount` say whether it is paid; list-payments with this `invoice_id` finds the payments.

    `names`, beside the record, names each account it refers to - `Name`, `Number`, `AccountType`, `Currency` -
    and each tax code, with its `Rate`. They come from the chart of accounts and tax codes kept for 24 hours, so
    usually cost nothing; when neither is kept, fetching them costs what list-accounts and list-tax-codes would,
    about one request per hundred accounts and one for the tax codes.

    Ids come from list-invoices: `TransactionId` there. One request, plus a login if none is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetInvoice extends GetRecord
{
    protected function named(): bool
    {
        return true;
    }

    protected function noun(): string
    {
        return 'invoice';
    }

    protected function argument(): string
    {
        return 'invoice_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->invoices()->find($id);
    }
}
