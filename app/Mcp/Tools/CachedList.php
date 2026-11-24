<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\Lookups;
use App\Saasu\SaasuCallException;
use App\Support\SaasuFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * A whole list kept for a day by `Lookups`, which says why. `refresh` fetches it again when
 * someone has just changed it in Saasu.
 */
abstract class CachedList extends SaasuTool
{
    /** The answer's key for the records: "accounts". */
    abstract protected function listName(): string;

    /**
     * @return array{fetched_at: string, records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     *
     * @throws SaasuCallException
     */
    abstract protected function fetch(Lookups $lookups, SaasuFile $file, bool $refresh): array;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'refresh' => $schema->boolean()->description(sprintf(
                'Fetch the list from Saasu again rather than use the copy kept for %d hours. Only when it has just been changed in Saasu.',
                Lookups::CACHE_HOURS,
            )),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $list = $this->fetch(new Lookups($gateway), $file, $request->get('refresh') === true);

        return [
            'count' => $list['count'],
            'fetched_at' => $list['fetched_at'],
            'note' => $list['more_exist']
                ? sprintf('The file has more than %d %s and only the first %d are here.', $list['count'], $this->listName(), $list['count'])
                : sprintf('Every one of the file\'s %s, as Saasu listed them at fetched_at. Kept for %d hours; `refresh` asks again.', $this->listName(), Lookups::CACHE_HOURS),
            $this->listName() => $list['records'],
        ];
    }
}
