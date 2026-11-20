<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Endpoint\Payments;
use Hampel\Saasu\Api\Enum\PaymentType;
use Hampel\Saasu\Api\Filter\DateRange;
use Hampel\Saasu\Api\Filter\PaymentFilter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List payments received against sales, or made against purchases, in a date range - or every payment applied
    to one invoice, with `invoice_id` and no dates.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5). Saasu does not say how many payments match, so when a page comes back full `more_exist` is true
    and the answer cannot say how many more.

    `from` and `to` are the payment date, inclusive, YYYY-MM-DD; they are required unless `invoice_id` is given,
    because Saasu otherwise answers for the last month only and that looks like a complete list. A listed payment
    does not show which invoices it paid: get-payment with its `TransactionId` does.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ListPayments extends SaasuTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'kind' => $schema->string()->enum(['sale', 'purchase'])
                ->description('sale for payments received, purchase for payments made. Required: a login without purchase permission is refused an untyped list.')->required(),
            'from' => $schema->string()->description('First payment date, YYYY-MM-DD. Needs `to`.'),
            'to' => $schema->string()->description('Last payment date, YYYY-MM-DD, inclusive. Needs `from`.'),
            'invoice_id' => $schema->integer()->min(1)->description('Only payments applied to this invoice, by its Saasu id.'),
            'bank_account_id' => $schema->integer()->min(1)->description('Only payments into or out of this bank account, by its Saasu id.'),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $kind = $this->choice($request->get('kind'), 'kind', ['sale', 'purchase']);
        $range = $this->range($request, 'from', 'to');
        $invoice = $this->id($request->get('invoice_id'), 'invoice_id', required: false);
        $account = $this->id($request->get('bank_account_id'), 'bank_account_id', required: false);
        $pages = $this->pageCount($request->get('pages'));

        if ($kind === null) {
            throw new SaasuCallException('`kind` is required: sale or purchase.');
        }

        if ($range === null && $invoice === null) {
            throw new SaasuCallException('Give `from` and `to`, or an `invoice_id`. Without a range Saasu answers for the last month only, which looks like a complete list.');
        }

        // Saasu refuses ForInvoiceId without a payment date range; a range no real payment falls
        // outside of is what Payments::forInvoice() sends
        $range ??= [Payments::EARLIEST, Payments::LATEST];

        $filter = PaymentFilter::all()
            ->ofType($kind === 'sale' ? PaymentType::SalePayment : PaymentType::PurchasePayment)
            ->paidBetween(DateRange::between(...$range));

        if ($invoice !== null) {
            $filter = $filter->forInvoice($invoice);
        }

        if ($account !== null) {
            $filter = $filter->intoAccount($account);
        }

        $result = $gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
            static fn (int $page) => $saasu->payments()->list($filter, $page),
            $pages,
        ));

        return [
            'count' => $result['count'],
            'more_exist' => $result['more_exist'],
            'note' => $this->moreNote($result),
            'dates' => Present::dateNote(),
            'payments' => $result['records'],
        ];
    }
}
