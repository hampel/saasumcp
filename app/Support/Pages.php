<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Hampel\Saasu\Api\Entity\Entity;
use Hampel\Saasu\Api\Result\Page;

/**
 * Fetch a Saasu list a page at a time, stopping at the first short page.
 */
final class Pages
{
    /**
     * @param  Closure(int): Page<covariant Entity>  $page  fetches one page by number
     * @return array{records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     */
    public static function fetch(Closure $page, int $pages): array
    {
        $records = [];
        $fetched = 0;
        $more = false;

        for ($number = 1; $number <= $pages; $number++) {
            $result = $page($number);
            $fetched++;

            foreach ($result as $entity) {
                $records[] = Present::record($entity);
            }

            $more = $result->hasMore();

            if (! $more) {
                break;
            }
        }

        return ['records' => $records, 'count' => count($records), 'pages_fetched' => $fetched, 'more_exist' => $more];
    }
}
