<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Hampel\Saasu\Api\Result\Page;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * A whole list that is small, changes rarely, and is looked up often: kept for a day.
 *
 * The chart of accounts and the tax codes are what an agent needs to read every invoice line, so
 * without a cache each conversation would spend the same few requests on them again. Kept in the
 * state directory's cache store, shared by every server process, for CACHE_HOURS. `refresh`
 * fetches it again when someone has just changed it in Saasu.
 */
abstract class CachedList extends SaasuTool
{
    public const CACHE_HOURS = 24;

    /** The answer's key for the records, and the cache's name for them: "accounts". */
    abstract protected function listName(): string;

    /**
     * One page of the list.
     *
     * @return Page<covariant Entity>
     */
    abstract protected function page(Client $saasu, int $page): Page;

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'refresh' => $schema->boolean()->description(sprintf(
                'Fetch the list from Saasu again rather than use the copy kept for %d hours. Only when it has just been changed in Saasu.',
                self::CACHE_HOURS,
            )),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $cache = app('cache')->store('saasumcp');

        $key = 'lookup.'.$file->fileId.'.'.$this->listName();
        $cached = $request->get('refresh') === true ? null : $cache->get($key);

        if (! is_array($cached)) {
            $result = $gateway->call($file, self::MAX_PAGES, fn (Client $saasu): array => $this->pages(
                fn (int $page): Page => $this->page($saasu, $page),
                self::MAX_PAGES,
            ));

            $cached = ['fetched_at' => Present::iso(time()), ...$result];

            $cache->put($key, $cached, self::CACHE_HOURS * 3600);
        }

        return [
            'count' => $cached['count'] ?? 0,
            'fetched_at' => $cached['fetched_at'] ?? null,
            'note' => ($cached['more_exist'] ?? false)
                ? sprintf('The file has more than %d %s and only the first %d are here.', $cached['count'] ?? 0, $this->listName(), $cached['count'] ?? 0)
                : sprintf('Every one of the file\'s %s, as Saasu listed them at fetched_at. Kept for %d hours; `refresh` asks again.', $this->listName(), self::CACHE_HOURS),
            $this->listName() => $cached['records'] ?? [],
        ];
    }
}
