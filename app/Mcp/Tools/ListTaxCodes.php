<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Result\Page;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    The file's tax codes - G1, G11, FRE and the rest - with each one's id, name, rate and whether it is active,
    so that the `TaxCode` on an invoice line, or an item's `SaleTaxCodeId`, can be read.

    A file not registered for GST may have none in use: its invoice lines carry no tax code at all.

    COST: kept for 24 hours and shared by every conversation, so usually nothing. Fetching it costs one request,
    plus a login if none is cached. `refresh` fetches it again.
    TEXT)]
#[IsReadOnly]
class ListTaxCodes extends CachedList
{
    protected function listName(): string
    {
        return 'tax_codes';
    }

    /** @return Page<covariant \Hampel\Saasu\Api\Entity\Entity> */
    protected function page(Client $saasu, int $page): Page
    {
        return $saasu->taxCodes()->list(null, $page);
    }
}
