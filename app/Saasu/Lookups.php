<?php

declare(strict_types=1);

namespace App\Saasu;

use App\Support\Pages;
use App\Support\Present;
use App\Support\SaasuFile;
use Closure;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Entity\Entity;
use Hampel\Saasu\Api\Filter\AccountFilter;
use Hampel\Saasu\Api\Result\Page;

/**
 * The lists that are small, change rarely, and are looked up often: kept for a day.
 *
 * The chart of accounts and the tax codes are what an agent needs to read every invoice line, so
 * without a cache each conversation would spend the same few requests on them again. Kept in the
 * state directory's cache store, shared by every server process, for CACHE_HOURS. list-accounts
 * and list-tax-codes answer from here, and so do the names a get-* tool puts beside a record.
 */
final class Lookups
{
    public const CACHE_HOURS = 24;

    /** The most pages one list may take: 500 accounts. */
    public const MAX_PAGES = 5;

    public function __construct(private readonly Gateway $gateway) {}

    /**
     * @return array{fetched_at: string, records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     *
     * @throws SaasuCallException
     */
    public function accounts(SaasuFile $file, bool $refresh = false): array
    {
        return $this->list($file, 'accounts', $refresh, static fn (Client $saasu, int $page): Page => $saasu->accounts()->list(AccountFilter::all()->includeBuiltIn(), $page));
    }

    /**
     * @return array{fetched_at: string, records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     *
     * @throws SaasuCallException
     */
    public function taxCodes(SaasuFile $file, bool $refresh = false): array
    {
        return $this->list($file, 'tax_codes', $refresh, static fn (Client $saasu, int $page): Page => $saasu->taxCodes()->list(null, $page));
    }

    /**
     * @param  Closure(Client, int): Page<covariant Entity>  $page
     * @return array{fetched_at: string, records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     *
     * @throws SaasuCallException
     */
    private function list(SaasuFile $file, string $name, bool $refresh, Closure $page): array
    {
        $cache = app('cache')->store('saasumcp');
        $key = 'lookup.'.$file->fileId.'.'.$name;
        $cached = $refresh ? null : $cache->get($key);

        if (is_array($cached) && isset($cached['fetched_at'], $cached['records'])) {
            /** @var array{fetched_at: string, records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool} $cached */
            return $cached;
        }

        $result = $this->gateway->call($file, self::MAX_PAGES, static fn (Client $saasu): array => Pages::fetch(
            static fn (int $number): Page => $page($saasu, $number),
            self::MAX_PAGES,
        ));

        $cached = ['fetched_at' => Present::iso(time()), ...$result];
        $cache->put($key, $cached, self::CACHE_HOURS * 3600);

        return $cached;
    }
}
