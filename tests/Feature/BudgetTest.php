<?php

use App\Saasu\BudgetSpentException;
use App\Saasu\DailyBudget;
use App\Support\SaasuFile;

function budgetAt(int &$now): DailyBudget
{
    return new DailyBudget(app('cache')->store('saasumcp'), function () use (&$now): int {
        return $now;
    });
}

function fileWithBudget(int $budget, int $fileId = 12345): SaasuFile
{
    return new SaasuFile('acme', 'Acme', $fileId, $budget, 'u', 'p');
}

it('counts what a call reserved, less what it gave back', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $file = fileWithBudget(10);

    $reservation = $budget->reserve($file, 4);
    expect($budget->spent($file))->toBe(4);

    $budget->settle($reservation, 1);
    expect($budget->spent($file))->toBe(1);
});

it('refuses a reservation that does not fit, and counts nothing for it', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $file = fileWithBudget(10);

    $budget->reserve($file, 8);

    expect(fn () => $budget->reserve($file, 3))->toThrow(BudgetSpentException::class)
        ->and($budget->spent($file))->toBe(8);
});

it('frees an hour only once all of it has left the window', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $file = fileWithBudget(10);
    $hour = intdiv($now, 3600) * 3600;

    $budget->reserve($file, 10);

    try {
        $budget->reserve($file, 1);
    } catch (BudgetSpentException $e) {
        expect($e->freesAt)->toBe($hour + 25 * 3600);
    }

    $now = $hour + 25 * 3600 - 1;
    expect($budget->spent($file))->toBe(10);

    $now = $hour + 25 * 3600;
    expect($budget->spent($file))->toBe(0);
});

it('keeps one count per Saasu file', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);

    $budget->reserve(fileWithBudget(10, 1), 9);

    expect($budget->spent(fileWithBudget(10, 2)))->toBe(0);
});

it('never gives back more than was reserved, nor counts below zero', function () {
    $now = 1_800_000_000;
    $budget = budgetAt($now);
    $file = fileWithBudget(10);

    $reservation = $budget->reserve($file, 3);
    $budget->settle($reservation, 7);

    expect($budget->spent($file))->toBe(3);
});

it('survives the process, because the count is in the cache store', function () {
    $now = 1_800_000_000;
    budgetAt($now)->reserve(fileWithBudget(10), 6);

    expect(budgetAt($now)->spent(fileWithBudget(10)))->toBe(6);
});
