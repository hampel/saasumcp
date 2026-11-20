<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one account in the chart of accounts by its Saasu id: name, number, type, level (header or
    detail), and for a bank account its currency and merchant fee account.

    list-accounts gives every account at once and is cached for the day, so prefer it for more than one. One
    request, plus a login if none is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetAccount extends GetRecord
{
    protected function noun(): string
    {
        return 'account';
    }

    protected function argument(): string
    {
        return 'account_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->accounts()->find($id);
    }
}
