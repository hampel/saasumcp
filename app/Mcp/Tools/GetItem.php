<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one inventory item by its Saasu id: code, description, prices, and the accounts and tax codes it
    is sold and bought with (`SaleIncomeAccountId`, `SaleTaxCodeId` and the rest).

    Ids appear on invoice lines (`InventoryId`) and come from find-items. One request, plus a login if none is
    cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetItem extends GetRecord
{
    protected function noun(): string
    {
        return 'item';
    }

    protected function argument(): string
    {
        return 'item_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->items()->find($id);
    }
}
