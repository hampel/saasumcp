<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\Pages;
use App\Support\SaasuFile;
use Closure;
use Hampel\Saasu\Api\Entity\Entity;
use Hampel\Saasu\Api\Result\Page;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that asks one Saasu file one question.
 *
 * Everything the tools share is here: resolving the file, reading an agent's arguments strictly,
 * turning a failure into a sentence instead of a protocol error, and the envelope every answer
 * goes out in - which always says what the call cost and what is left. A subclass says what to
 * ask, and how many requests it may take.
 */
abstract class SaasuTool extends Tool
{
    /** The most pages any list tool fetches in one call. A page is 100 records and one request. */
    public const MAX_PAGES = 5;

    /**
     * @return array<mixed>
     *
     * @throws SaasuCallException
     */
    abstract protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array;

    public function handle(Request $request, Gateway $gateway): Response|ResponseFactory
    {
        $gateway->resetLastSpent();

        try {
            $file = $gateway->file($this->text($request->get('file'), 'file') ?? '');
            $answer = $this->answer($request, $gateway, $file);
        } catch (SaasuCallException $e) {
            // an error RESULT, not a protocol error: the agent should read why and carry on
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'file' => ['file' => $file->key, 'name' => $file->name],
            ...$answer,
            'budget' => $gateway->budget($file),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['file' => $this->fileArgument($schema)];
    }

    /**
     * Only what every answer carries. The rest is Saasu's own record, documented in each tool's
     * description, and not restated as a schema that would have to be kept in step with Saasu.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'file' => $schema->object()->description('The Saasu file that answered.')->required(),
            'budget' => $schema->object()->description('Requests this call sent, logins included, and what this server has used of its own limit for the file in the last 24 hours.')->required(),
        ];
    }

    protected function fileArgument(JsonSchema $schema): Type
    {
        return $schema->string()
            ->description('The key of the Saasu file to ask, as returned by list-files.')
            ->required();
    }

    protected function pagesArgument(JsonSchema $schema): Type
    {
        return $schema->integer()->min(1)->max(self::MAX_PAGES)->default(1)
            ->description(sprintf('How many pages of 100 to fetch, 1 to %d. Each page is one request against the file\'s daily budget. Default 1.', self::MAX_PAGES));
    }

    /**
     * Fetch up to $pages pages, stopping at the first short one.
     *
     * @param  Closure(int): Page<covariant Entity>  $page  fetches one page by number
     * @return array{records: list<array<mixed>>, count: int, pages_fetched: int, more_exist: bool}
     */
    protected function pages(Closure $page, int $pages): array
    {
        return Pages::fetch($page, $pages);
    }

    /**
     * Said beside a list, because a list has no total and silence would read as "that is all".
     *
     * @param  array{more_exist: bool, pages_fetched: int}  $result
     */
    protected function moreNote(array $result): string
    {
        if (! $result['more_exist']) {
            return 'This is everything that matched.';
        }

        return $result['pages_fetched'] < self::MAX_PAGES
            ? 'More records match than were fetched. Saasu does not say how many. Ask for more pages, or narrow the filter.'
            : 'More records match than one call may fetch. Saasu does not say how many. Narrow the filter rather than paging on.';
    }

    /** @throws SaasuCallException */
    protected function id(mixed $value, string $argument, bool $required = true): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new SaasuCallException('`'.$argument.'` is required: the Saasu id, a positive whole number.');
            }

            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value)) && (int) trim($value) > 0) {
            return (int) trim($value);
        }

        throw new SaasuCallException('`'.$argument.'` must be a Saasu id, a positive whole number.');
    }

    /** @throws SaasuCallException */
    protected function date(mixed $value, string $argument): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return trim($value);
        }

        throw new SaasuCallException('`'.$argument.'` must be a date, YYYY-MM-DD.');
    }

    /** @throws SaasuCallException */
    protected function text(mixed $value, string $argument): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new SaasuCallException('`'.$argument.'` must be text.');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @throws SaasuCallException */
    protected function currency(mixed $value): ?string
    {
        $value = $this->text($value, 'currency');

        if ($value === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z]{3}$/', $value) !== 1) {
            throw new SaasuCallException('`currency` must be a three-letter currency code, such as USD.');
        }

        return strtoupper($value);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws SaasuCallException
     */
    protected function choice(mixed $value, string $argument, array $allowed, ?string $default = null): ?string
    {
        $value = $this->text($value, $argument) ?? $default;

        if ($value === null || in_array($value, $allowed, true)) {
            return $value;
        }

        throw new SaasuCallException('`'.$argument.'` must be one of: '.implode(', ', $allowed).'.');
    }

    /** @throws SaasuCallException */
    protected function pageCount(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        if (is_numeric($value) && (int) $value == $value && (int) $value >= 1 && (int) $value <= self::MAX_PAGES) {
            return (int) $value;
        }

        throw new SaasuCallException(sprintf('`pages` must be a whole number from 1 to %d.', self::MAX_PAGES));
    }

    /**
     * A from-to pair, both or neither.
     *
     * @return array{0: string, 1: string}|null
     *
     * @throws SaasuCallException
     */
    protected function range(Request $request, string $from, string $to): ?array
    {
        $start = $this->date($request->get($from), $from);
        $end = $this->date($request->get($to), $to);

        if ($start === null && $end === null) {
            return null;
        }

        if ($start === null || $end === null) {
            throw new SaasuCallException(sprintf('Give both `%s` and `%s`, or neither: Saasu only accepts a whole range.', $from, $to));
        }

        if ($start > $end) {
            throw new SaasuCallException(sprintf('`%s` is after `%s`.', $from, $to));
        }

        return [$start, $end];
    }
}
