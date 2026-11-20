<?php

declare(strict_types=1);

namespace App\Saasu;

use App\Support\SaasuFile;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * How many requests this server has sent each Saasu file in the last 24 hours, kept across every
 * server process.
 *
 * THE ONE SAFETY-CRITICAL THING THIS TOOL DOES. Saasu blocks a file's whole API for 24 hours once
 * its daily quota is exceeded - every integration on it, saasusync's morning bookings included -
 * and the people asking questions through this server cannot see that quota. So the server keeps
 * its own, far below Saasu's, and refuses a call that would pass it.
 *
 * PERSISTED, because an MCP client starts a server process per session: a count kept in the
 * process would reset every time a conversation began. It is in the cache store, under a lock, so
 * two conversations at once share one count.
 *
 * A ROLLING 24 HOURS, NOT A CALENDAR DAY. When Saasu's own day starts is not documented, and a
 * window that ends at midnight somewhere lets a whole budget be spent either side of it. Counting
 * by the hour and summing the last 24 can never allow more than one budget in any day Saasu might
 * mean. An hour is counted whole while any of it is in the window, which errs towards refusing.
 *
 * RESERVE, THEN SETTLE. A call first sets aside the most it might send, under the lock, so that two
 * processes cannot each see the same room and both spend it; it then gives back what it did not
 * use. A call is refused outright when its reservation does not fit, rather than started and cut
 * off half way.
 */
class DailyBudget
{
    public const HOUR = 3600;

    public const WINDOW_HOURS = 24;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  answers Unix seconds; a test moves time with it
     */
    public function __construct(
        private readonly Repository $cache,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Set aside $requests for one call to $file, or refuse.
     *
     * @throws BudgetSpentException
     */
    public function reserve(SaasuFile $file, int $requests): Reservation
    {
        return $this->locked($file->fileId, function () use ($file, $requests): Reservation {
            $now = ($this->clock)();
            $hours = $this->window($this->read($file->fileId), $now);
            $spent = array_sum($hours);

            if ($spent + $requests > $file->dailyBudget) {
                throw new BudgetSpentException($spent, $file->dailyBudget, $requests, $this->freesAt($hours, $spent + $requests - $file->dailyBudget));
            }

            $hour = $this->hour($now);
            $hours[$hour] = ($hours[$hour] ?? 0) + $requests;

            $this->write($file->fileId, $hours);

            return new Reservation($file->fileId, $hour, $requests);
        });
    }

    /**
     * Give back what a reservation did not use. Called whether the call succeeded or not: a
     * request that was sent and failed still counted against Saasu's quota.
     */
    public function settle(Reservation $reservation, int $used): void
    {
        $unused = $reservation->requests - max(0, $used);

        if ($unused <= 0) {
            return;
        }

        $this->locked($reservation->fileId, function () use ($reservation, $unused): void {
            $hours = $this->window($this->read($reservation->fileId), ($this->clock)());

            if (! isset($hours[$reservation->hour])) {
                return; // the hour has left the window
            }

            $hours[$reservation->hour] = max(0, $hours[$reservation->hour] - $unused);

            $this->write($reservation->fileId, $hours);
        });
    }

    /** Requests counted against $file in the window, reservations in flight included. */
    public function spent(SaasuFile $file): int
    {
        return array_sum($this->window($this->read($file->fileId), ($this->clock)()));
    }

    public function remaining(SaasuFile $file): int
    {
        return max(0, $file->dailyBudget - $this->spent($file));
    }

    /**
     * When enough of the oldest hours leave the window to free $needed requests.
     *
     * @param  array<int, int>  $hours
     */
    private function freesAt(array $hours, int $needed): int
    {
        ksort($hours);

        $freed = 0;

        foreach ($hours as $hour => $count) {
            $freed += $count;

            if ($freed >= $needed) {
                return $hour + (self::WINDOW_HOURS + 1) * self::HOUR;
            }
        }

        // asked for more than a whole budget: never, but say when the window is next empty
        return $this->hour(($this->clock)()) + (self::WINDOW_HOURS + 1) * self::HOUR;
    }

    /**
     * @param  array<int, int>  $hours
     * @return array<int, int>
     */
    private function window(array $hours, int $now): array
    {
        $oldest = $this->hour($now) - self::WINDOW_HOURS * self::HOUR;

        return array_filter($hours, static fn (int $count, int $hour): bool => $hour >= $oldest && $count > 0, ARRAY_FILTER_USE_BOTH);
    }

    private function hour(int $time): int
    {
        return intdiv($time, self::HOUR) * self::HOUR;
    }

    /** @return array<int, int> */
    private function read(int $fileId): array
    {
        $stored = $this->cache->get($this->key($fileId));

        if (! is_array($stored)) {
            return [];
        }

        $hours = [];

        foreach ($stored as $hour => $count) {
            if (is_int($hour) && is_int($count)) {
                $hours[$hour] = $count;
            }
        }

        return $hours;
    }

    /** @param  array<int, int>  $hours */
    private function write(int $fileId, array $hours): void
    {
        // no expiry: an expiry is a way for the count to vanish early
        $this->cache->forever($this->key($fileId), $hours);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(int $fileId, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new \LogicException('the cache store keeping the request budget does not support locks');
        }

        // waits up to ten seconds for another process's read-and-write, which takes milliseconds
        return $store->lock($this->key($fileId).'.lock', 10)->block(10, $callback);
    }

    private function key(int $fileId): string
    {
        return 'budget.'.$fileId;
    }
}
