<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one contact by its Saasu id: name, company, email, addresses, whether customer or supplier, and
    trading terms.

    `ContactId` in the answer is NOT the contact's id: it is a reference another system set, such as a forum
    user id. `Id` is Saasu's own.

    Ids appear on invoices (`BillingContactId`) and come from find-contacts. One request, plus a login if none
    is cached.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetContact extends GetRecord
{
    protected function noun(): string
    {
        return 'contact';
    }

    protected function argument(): string
    {
        return 'contact_id';
    }

    protected function find(Client $saasu, int $id): ?Entity
    {
        return $saasu->contacts()->find($id);
    }
}
