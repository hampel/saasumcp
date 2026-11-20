<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Filter\ItemFilter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Find inventory items with Saasu's item search, asking for those that contain some text. `search` is required: there is no way to
    list every item.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5). get-item has the full record for one.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class FindItems extends SaasuTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'search' => $schema->string()->description('Text to search the items for.')->required(),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $search = $this->text($request->get('search'), 'search')
            ?? throw new SaasuCallException('`search` is required: text to search the items for.');

        $pages = $this->pageCount($request->get('pages'));
        $filter = ItemFilter::all()->search($search);

        $result = $gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
            static fn (int $page) => $saasu->items()->list($filter, $page),
            $pages,
        ));

        return [
            'count' => $result['count'],
            'more_exist' => $result['more_exist'],
            'note' => $this->moreNote($result),
            'items' => $result['records'],
        ];
    }
}
