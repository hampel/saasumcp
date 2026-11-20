<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Filter\ContactFilter;
use Hampel\Saasu\Api\Filter\DateRange;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Find contacts by email, by the reference another system gave them, by name or company, or by when they were
    last changed. At least one is required: there is no way to list every contact.

    `email` and `reference` are matched whole, ignoring case; `email` matches the PRIMARY address only, not a
    contact's additional ones (measured). How Saasu matches the names has not been measured, so a name that finds
    nothing is not proof there is no such contact - try the email. Several filters together must all match.
    `reference` is Saasu's `ContactId` field, where another system keeps its own id for the contact; it is not
    unique, so more than one contact can answer.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5).
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class FindContacts extends SaasuTool
{
    private const TEXT_FILTERS = ['email', 'reference', 'company_name', 'given_name', 'family_name'];

    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'email' => $schema->string()->description('An email address.'),
            'reference' => $schema->string()->description('Another system\'s id for the contact, kept in Saasu\'s ContactId field.'),
            'company_name' => $schema->string()->description('The company\'s name.'),
            'given_name' => $schema->string()->description('A first name.'),
            'family_name' => $schema->string()->description('A surname.'),
            'modified_from' => $schema->string()->description('Changed on or after this date, YYYY-MM-DD. Needs `modified_to`.'),
            'modified_to' => $schema->string()->description('Changed on or before this date, YYYY-MM-DD. Needs `modified_from`.'),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $given = [];

        foreach (self::TEXT_FILTERS as $name) {
            $value = $this->text($request->get($name), $name);

            if ($value !== null) {
                $given[$name] = $value;
            }
        }

        $range = $this->range($request, 'modified_from', 'modified_to');
        $pages = $this->pageCount($request->get('pages'));

        if ($given === [] && $range === null) {
            throw new SaasuCallException('Give at least one of `email`, `reference`, `company_name`, `given_name`, `family_name`, or `modified_from` with `modified_to`. Listing every contact is how a file\'s daily quota gets spent.');
        }

        $filter = ContactFilter::all();

        foreach ($given as $name => $value) {
            $filter = match ($name) {
                'email' => $filter->withEmail($value),
                'reference' => $filter->reference($value),
                'company_name' => $filter->companyName($value),
                'given_name' => $filter->givenName($value),
                'family_name' => $filter->familyName($value),
            };
        }

        if ($range !== null) {
            $filter = $filter->modifiedBetween(DateRange::between(...$range));
        }

        $result = $gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
            static fn (int $page) => $saasu->contacts()->list($filter, $page),
            $pages,
        ));

        return [
            'count' => $result['count'],
            'more_exist' => $result['more_exist'],
            'note' => $this->moreNote($result),
            'contacts' => $result['records'],
        ];
    }
}
