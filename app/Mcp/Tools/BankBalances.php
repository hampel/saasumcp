<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    The current balance of each bank account in the file, as Saasu's books have it - which is what has been entered
    and reconciled, not necessarily what the bank says today.

    Each entry is an `AccountId`, `AccountName` and `Balance`; the account's currency is in list-accounts. One request, plus a login if
    none is cached; not cached, because a balance is the thing that changes.
    TEXT)]
#[IsReadOnly]
class BankBalances extends SaasuTool
{
    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $balances = $gateway->call($file, 1, static fn (Client $saasu): array => $saasu->accounts()->bankBalances());

        return [
            'count' => count($balances),
            'balances' => Present::records($balances),
        ];
    }
}
