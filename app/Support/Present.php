<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Hampel\Saasu\Api\Entity\Entity;

/**
 * How a Saasu record is laid out for an agent.
 *
 * A record goes out as Saasu sent it - the package keeps it in `raw` - so a field this tool has
 * never heard of still reaches the agent, and an invoice type the package's enum lacks arrives as
 * the string Saasu wrote rather than as nothing. Only two things change.
 */
final class Present
{
    /**
     * A record, as Saasu sent it, without its hypermedia links.
     *
     * `_links` are URLs into the API with the file id in them: no use to an agent that cannot
     * follow them, and on an invoice with attachments they are a third of the answer.
     *
     * @return array<mixed>
     */
    public static function record(Entity $entity): array
    {
        return self::withoutLinks($entity->raw);
    }

    /**
     * @param  iterable<Entity>  $entities
     * @return list<array<mixed>>
     */
    public static function records(iterable $entities): array
    {
        $records = [];

        foreach ($entities as $entity) {
            $records[] = self::record($entity);
        }

        return $records;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function withoutLinks(array $data): array
    {
        unset($data['_links']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutLinks($value);
            }
        }

        return $data;
    }

    /**
     * Said in every answer that carries a record, because the misreading costs a day.
     *
     * Saasu writes a transaction date with a meaningless midnight attached. Converted to a local
     * timezone, an invoice dated the 14th becomes the 13th - so the note says not to.
     */
    public static function dateNote(): string
    {
        $note = 'Transaction dates (TransactionDate, DueDate, ClearedDate) are calendar dates in the file\'s own books: '
            .'read the date part and ignore the time, and never convert them between timezones. Fields ending in Utc are '
            .'instants in UTC.';

        $zone = (string) config('saasumcp.timezone');

        return $zone !== '' && $zone !== 'UTC'
            ? $note.' The local time zone here is '.$zone.'.'
            : $note;
    }

    /** A Unix time as ISO 8601 in UTC. */
    public static function iso(int $time): string
    {
        return CarbonImmutable::createFromTimestamp($time, 'UTC')->toIso8601ZuluString();
    }
}
