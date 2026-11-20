<?php

declare(strict_types=1);

namespace App\Saasu;

/**
 * Requests set aside for one call, in the hour they were counted against.
 */
final readonly class Reservation
{
    public function __construct(
        public int $fileId,
        public int $hour,
        public int $requests,
    ) {}
}
