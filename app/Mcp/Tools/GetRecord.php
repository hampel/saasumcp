<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\Lookups;
use App\Saasu\SaasuCallException;
use App\Support\Names;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * One record by its id: one request, plus a login if none is cached - and, for a record whose
 * lines name accounts and tax codes by id, those names beside it from the day-cached lists.
 */
abstract class GetRecord extends SaasuTool
{
    /** What the record is, as a sentence names it: "invoice". */
    abstract protected function noun(): string;

    /** The argument the id arrives in: "invoice_id". */
    abstract protected function argument(): string;

    /** The record, or null when the file has none with that id. */
    abstract protected function find(Client $saasu, int $id): ?Entity;

    /** Whether the answer names the accounts and tax codes the record refers to. */
    protected function named(): bool
    {
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            $this->argument() => $schema->integer()->min(1)
                ->description(sprintf('The Saasu id of the %s - its own id in the file, not a number printed on it.', $this->noun()))
                ->required(),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $id = (int) $this->id($request->get($this->argument()), $this->argument());

        $record = $gateway->call($file, 1, fn (Client $saasu): ?Entity => $this->find($saasu, $id));

        if ($record === null) {
            throw new SaasuCallException(sprintf('"%s" has no %s with id %d.', $file->key, $this->noun(), $id));
        }

        $presented = Present::record($record);
        $names = $this->named() ? Names::for($presented, new Lookups($gateway), $file) : null;

        return [
            'dates' => Present::dateNote(),
            $this->noun() => $presented,
            ...($names === null ? [] : ['names' => $names]),
        ];
    }
}
