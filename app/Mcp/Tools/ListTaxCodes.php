<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Lookups;
use App\Support\SaasuFile;
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

    protected function fetch(Lookups $lookups, SaasuFile $file, bool $refresh): array
    {
        return $lookups->taxCodes($file, $refresh);
    }
}
