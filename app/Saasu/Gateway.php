<?php

declare(strict_types=1);

namespace App\Saasu;

use App\Support\FileInventory;
use App\Support\FileInventoryException;
use App\Support\SaasuFile;
use App\Support\StateDirectory;
use Closure;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Exception\ApiException;
use Hampel\Saasu\Api\Exception\ExceptionInterface;
use Hampel\Saasu\Api\Exception\MalformedResponseException;
use Hampel\Saasu\Api\Exception\NotAuthenticatedException;
use Hampel\Saasu\Api\Exception\NotFoundException;
use Hampel\Saasu\Api\Exception\NotPermittedException;
use Hampel\Saasu\Api\Exception\RequestBudgetExhaustedException;
use Hampel\Saasu\Api\Exception\RequestException;
use Hampel\Saasu\Api\Exception\ServerException;
use Hampel\Saasu\Api\Exception\TooManyRequestsException;
use Hampel\Saasu\Api\Exception\TwoFactorRequiredException;
use Hampel\Saasu\Api\Exception\ValidationException;
use Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Illuminate\Support\Carbon;

/**
 * Every request this server sends to Saasu goes through here.
 *
 * It resolves a file key to a client logged in as that file's own login, holds each call to the
 * file's daily budget, and turns every failure into a sentence. A tool says what to ask and how
 * many requests that may take; it never builds a client itself.
 */
class Gateway
{
    /**
     * Requests set aside on top of what a call asks for, for logging in: one for a token when
     * none is cached, and one more for the fresh token OAuth asks for when Saasu rejects a cached
     * one early. Token requests count against Saasu's quota like any other.
     */
    public const LOGIN_ALLOWANCE = 2;

    /** Requests the last call sent, logins included. */
    private int $lastSpent = 0;

    public function __construct(
        private readonly FileInventory $inventory,
        private readonly SaasuManager $manager,
        private readonly DailyBudget $budget,
    ) {}

    /** @throws SaasuCallException */
    public function file(string $key): SaasuFile
    {
        try {
            $file = $this->inventory->get(trim($key));
        } catch (FileInventoryException $e) {
            throw new SaasuCallException(ucfirst($e->getMessage()).'. Call list-files for the files this server can read.');
        }

        if (! $file->hasCredentials()) {
            throw new SaasuCallException(sprintf(
                'The Saasu file "%s" has no login configured on this server (%s and %s). Run `saasumcp app:validate` where the server runs.',
                $file->key,
                $file->variable('username'),
                $file->variable('password'),
            ));
        }

        return $file;
    }

    /**
     * Run $call against $file, allowed at most $requests requests plus a login.
     *
     * @template T
     *
     * @param  Closure(Client): T  $call
     * @return T
     *
     * @throws SaasuCallException
     */
    public function call(SaasuFile $file, int $requests, Closure $call): mixed
    {
        $this->lastSpent = 0;
        $allowed = $requests + self::LOGIN_ALLOWANCE;

        try {
            $reservation = $this->budget->reserve($file, $allowed);
        } catch (BudgetSpentException $e) {
            throw new SaasuCallException($this->budgetSpent($file, $e));
        }

        $client = null;

        try {
            $client = $this->client($file)->withRequestBudget($allowed);

            return $call($client);
        } catch (ExceptionInterface $e) {
            throw new SaasuCallException($this->explain($file, $e, $allowed));
        } finally {
            $this->lastSpent = $client?->requestsSent() ?? 0;
            $this->budget->settle($reservation, $this->lastSpent);
        }
    }

    public function lastSpent(): int
    {
        return $this->lastSpent;
    }

    /**
     * Start a tool call's count at zero. The gateway lives for the process, so without this an
     * answer that sent nothing - a cached list - reports what the previous call sent.
     */
    public function resetLastSpent(): void
    {
        $this->lastSpent = 0;
    }

    /**
     * The file's budget as an answer reports it.
     *
     * @return array<string, int>
     */
    public function budget(SaasuFile $file): array
    {
        return [
            'requests_this_call' => $this->lastSpent,
            'used_last_24h' => $this->budget->spent($file),
            'daily_budget' => $file->dailyBudget,
        ];
    }

    private function client(SaasuFile $file): Client
    {
        // tokens and throttle slots are about to be written into it
        StateDirectory::ensure();

        // the file id is in the name, so an inventory edited while the server runs gets a new
        // client rather than the one the manager kept for the old file
        $name = $file->connection().'-'.$file->fileId;

        config(['saasu.connections.'.$name => [
            'file_id' => $file->fileId,
            'username' => $file->username,
            'password' => $file->password(),
        ]]);

        try {
            return $this->manager->client($name);
        } catch (InvalidConfiguration $e) {
            throw new SaasuCallException('This server\'s Saasu settings are not usable: '.$e->getMessage());
        }
    }

    private function budgetSpent(SaasuFile $file, BudgetSpentException $e): string
    {
        $freesAt = Carbon::createFromTimestamp($e->freesAt, 'UTC');

        return sprintf(
            'Refused without asking Saasu: this server has used %d of the %d requests it allows itself for "%s" in any 24 hours, '
            .'and this call needs up to %d. Room for it frees at %s. The limit is this server\'s own and stays far below '
            .'Saasu\'s quota on purpose, because exceeding that blocks the whole file for 24 hours, for every integration '
            .'using it. Do not work round it with other tools; say what you could not look up.',
            $e->spent,
            $e->budget,
            $file->key,
            $e->wanted,
            $freesAt->toIso8601ZuluString(),
        );
    }

    private function explain(SaasuFile $file, ExceptionInterface $e, int $allowed): string
    {
        $whose = sprintf('the login this server uses for "%s"', $file->key);

        return match (true) {
            $e instanceof RequestBudgetExhaustedException => sprintf(
                'Stopped before sending more: this call was allowed %d requests and needed more. Nothing was changed. '
                .'Ask for less - a shorter date range, or fewer pages.',
                $allowed,
            ),
            $e instanceof TwoFactorRequiredException => ucfirst($whose).' has two-factor authentication on, which a server '
                .'cannot answer. It needs a login without it.',
            $e instanceof NotAuthenticatedException => sprintf(
                'Saasu refused %s: its username or password is wrong, or the user has been removed from the file. '
                .'This is a configuration problem on the server, not an empty answer.',
                $whose,
            ),
            $e instanceof NotPermittedException => sprintf(
                'Saasu refused %s permission for this. The login is deliberately limited; what it may see is set in '
                .'Saasu, under the file\'s users. Saasu said: %s',
                $whose,
                $this->saasuSaid($e),
            ),
            $e instanceof ValidationException => sprintf(
                'Saasu refused the request: %s. A login that lacks a permission is sometimes refused this way rather '
                .'than with a permission error - a payment list in particular answers about a "null collection".',
                $this->saasuSaid($e),
            ),
            $e instanceof NotFoundException && $e->isUnroutedPath() => 'Saasu answered with an error page rather than a '
                .'record, which is a wrong path - a bug in this server, not a missing record.',
            $e instanceof NotFoundException => sprintf('"%s" has no such record. Saasu said: %s', $file->key, $this->saasuSaid($e)),
            $e instanceof TooManyRequestsException => 'Saasu answered "too many requests". Stop asking this file for now: '
                .'if its daily quota is exceeded Saasu blocks the whole file for 24 hours.',
            $e instanceof ServerException => sprintf('Saasu failed with an error of its own (HTTP %d). Try again later.', $e->statusCode),
            $e instanceof MalformedResponseException => 'Saasu answered with something that is not JSON, such as a '
                .'maintenance or proxy page. Try again later.',
            $e instanceof RequestException => 'Saasu could not be reached: the connection failed or timed out.',
            $e instanceof ApiException => sprintf('Saasu refused the request (HTTP %d): %s', $e->statusCode, $this->saasuSaid($e)),
            default => 'The call to Saasu failed ('.class_basename($e).').',
        };
    }

    /**
     * Saasu's own error text, which is prose and sometimes .NET exception text. The package has
     * already refused to quote an HTML page and removed any access key; this keeps it short.
     */
    private function saasuSaid(ApiException $e): string
    {
        $said = trim(implode(' ', $e->messages())) ?: trim($e->getMessage());

        return mb_strlen($said) > 400 ? mb_substr($said, 0, 400).'...' : rtrim($said, '.');
    }
}
