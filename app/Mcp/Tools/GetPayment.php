<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one payment by its Saasu id: its date, the bank account (`PaymentAccountId`), currency and rate
    (`Currency`, `FxRate`), any merchant fee taken from it (`FeeAmount`), and in `PaymentItems` each invoice it paid
    (`InvoiceTransactionId`) with the amount applied.

    `Reference`, `Summary` and `Notes` are free text; another system's own reference - a PayPal transaction id, say
    - is usually kept in one of them.

    `names`, beside the record, names each account it refers to - `Name`, `Number`, `AccountType`, `Currency` -
    and each tax code, with its `Rate`. They come from the chart of accounts and tax codes kept for 24 hours, so
    usually cost nothing; when neither is kept, fetching them costs what list-accounts and list-tax-codes would,
    about one request per hundred accounts and one for the tax codes.

    Ids come from list-payments: `TransactionId` there. One request, plus a login if none is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetPayment extends GetRecord
{
    protected function named(): bool
    {
        return true;
    }

    protected function noun(): string
    {
        return 'payment';
    }

    protected function argument(): string
    {
        return 'payment_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->payments()->find($id);
    }
}
