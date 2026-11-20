<?php

declare(strict_types=1);

namespace App\Saasu;

final class BudgetSpentException extends \RuntimeException
{
    public function __construct(
        public readonly int $spent,
        public readonly int $budget,
        public readonly int $wanted,
        public readonly int $freesAt,
    ) {
        parent::__construct('the daily request budget is spent');
    }
}
