<?php

declare(strict_types=1);

namespace App\Support;

use App\Saasu\Lookups;
use App\Saasu\SaasuCallException;

/**
 * The names of the accounts and tax codes a record refers to by id, so that a line reads
 * "PayPal USD" rather than 3740547.
 *
 * They come from the day-cached chart of accounts and tax codes, so they usually cost nothing;
 * when the cache is cold they cost what list-accounts and list-tax-codes would. They go beside
 * the record, never into it: the record is still Saasu's, as sent. A lookup that cannot be made -
 * the budget, say - leaves the record unnamed and says why, rather than failing an answer that
 * already has what was asked for.
 */
final class Names
{
    /**
     * @param  array<mixed>  $record  as Present::record() laid it out
     * @return array<string, mixed>|null null when the record names no account and no tax code
     */
    public static function for(array $record, Lookups $lookups, SaasuFile $file): ?array
    {
        $accounts = [];
        $codes = [];
        self::collect($record, $accounts, $codes);

        if ($accounts === [] && $codes === []) {
            return null;
        }

        $names = [];
        $notes = [];

        if ($accounts !== []) {
            try {
                $chart = $lookups->accounts($file);
                $byId = [];

                foreach ($chart['records'] as $account) {
                    if (isset($account['Id']) && is_int($account['Id'])) {
                        $byId[$account['Id']] = $account;
                    }
                }

                foreach ($accounts as $id) {
                    $names['accounts'][$id] = isset($byId[$id]) ? self::pick($byId[$id], ['Name', 'Number', 'AccountType', 'Currency', 'IsBankAccount', 'IsActive']) : null;
                }

                if (in_array(null, $names['accounts'], true)) {
                    $notes[] = $chart['more_exist']
                        ? sprintf('An account given as null is not among the first %d in the chart of accounts, which is all one list fetches.', $chart['count'])
                        : 'An account given as null is not in the chart of accounts as fetched; `refresh` on list-accounts asks again.';
                }
            } catch (SaasuCallException $e) {
                $notes[] = 'Accounts are not named: '.$e->getMessage();
            }
        }

        if ($codes !== []) {
            try {
                $byCode = [];

                foreach ($lookups->taxCodes($file)['records'] as $code) {
                    if (isset($code['Code']) && is_string($code['Code'])) {
                        $byCode[$code['Code']] = $code;
                    }
                }

                foreach ($codes as $code) {
                    $names['tax_codes'][$code] = isset($byCode[$code]) ? self::pick($byCode[$code], ['Name', 'Rate', 'IsActive']) : null;
                }
            } catch (SaasuCallException $e) {
                $notes[] = 'Tax codes are not named: '.$e->getMessage();
            }
        }

        $notes[] = sprintf('Named from the chart of accounts and tax codes kept for %d hours, as list-accounts and list-tax-codes show them.', Lookups::CACHE_HOURS);

        return $names + ['note' => implode(' ', $notes)];
    }

    /**
     * Every account id and tax code in a record, at any depth: `AccountId`, `PaymentAccountId`
     * and the rest are named by a key ending in AccountId, a tax code by one ending in TaxCode.
     *
     * @param  array<mixed>  $data
     * @param  list<int>  $accounts
     * @param  list<string>  $codes
     */
    private static function collect(array $data, array &$accounts, array &$codes): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::collect($value, $accounts, $codes);
            } elseif (is_string($key) && str_ends_with($key, 'AccountId') && is_int($value) && $value > 0) {
                if (! in_array($value, $accounts, true)) {
                    $accounts[] = $value;
                }
            } elseif (is_string($key) && str_ends_with($key, 'TaxCode') && is_string($value) && $value !== '') {
                if (! in_array($value, $codes, true)) {
                    $codes[] = $value;
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $record
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function pick(array $record, array $fields): array
    {
        $picked = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null) {
                $picked[$field] = $record[$field];
            }
        }

        return $picked;
    }
}
