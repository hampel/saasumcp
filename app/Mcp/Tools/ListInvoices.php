<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Saasu\SaasuCallException;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Enum\PaymentStatus;
use Hampel\Saasu\Api\Filter\DateRange;
use Hampel\Saasu\Api\Filter\InvoiceFilter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List sale or purchase invoices in a file, narrowed by date, number or contact. There is no way to list every
    invoice: give `from` and `to`, or an `invoice_number`, `purchase_order_number` or `contact_id`.

    COST: one request per page of 100, plus a login if none is cached - `pages` sets the most it may fetch (default
    1, at most 5). Saasu does not say how many invoices match, so when a page comes back full `more_exist` is true
    and the answer cannot say how many more. Narrow the filter before asking for more pages.

    A listed invoice has no line items: get-invoice with its `TransactionId` has them. `from` and `to` are the
    invoice's transaction date, inclusive, YYYY-MM-DD.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ListInvoices extends SaasuTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'kind' => $schema->string()->enum(['sale', 'purchase'])
                ->description('sale or purchase. Saasu lists both together otherwise, so this is required.')->required(),
            'from' => $schema->string()->description('First transaction date, YYYY-MM-DD. Needs `to`.'),
            'to' => $schema->string()->description('Last transaction date, YYYY-MM-DD, inclusive. Needs `from`.'),
            'invoice_number' => $schema->string()->description('An invoice number, matched whole.'),
            'purchase_order_number' => $schema->string()->description('A purchase order number, matched whole.'),
            'contact_id' => $schema->integer()->min(1)->description('The Saasu id of the contact billed.'),
            'payment_status' => $schema->string()->enum(['paid', 'unpaid'])->description('Only paid, or only unpaid, invoices.'),
            'pages' => $this->pagesArgument($schema),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $kind = $this->choice($request->get('kind'), 'kind', ['sale', 'purchase']);
        $range = $this->range($request, 'from', 'to');
        $number = $this->text($request->get('invoice_number'), 'invoice_number');
        $order = $this->text($request->get('purchase_order_number'), 'purchase_order_number');
        $contact = $this->id($request->get('contact_id'), 'contact_id', required: false);
        $status = $this->choice($request->get('payment_status'), 'payment_status', ['paid', 'unpaid']);
        $pages = $this->pageCount($request->get('pages'));

        if ($kind === null) {
            throw new SaasuCallException('`kind` is required: sale or purchase.');
        }

        if ($range === null && $number === null && $order === null && $contact === null) {
            throw new SaasuCallException('Narrow the list: give `from` and `to`, or an `invoice_number`, `purchase_order_number` or `contact_id`. Listing every invoice in a file is how its daily quota gets spent.');
        }

        $filter = $kind === 'sale' ? InvoiceFilter::sales() : InvoiceFilter::purchases();

        if ($range !== null) {
            $filter = $filter->datedBetween(DateRange::between(...$range));
        }

        if ($number !== null) {
            $filter = $filter->invoiceNumber($number);
        }

        if ($order !== null) {
            $filter = $filter->purchaseOrderNumber($order);
        }

        if ($contact !== null) {
            $filter = $filter->billedTo($contact);
        }

        if ($status !== null) {
            $filter = $filter->paymentStatus($status === 'paid' ? PaymentStatus::Paid : PaymentStatus::Unpaid);
        }

        $result = $gateway->call($file, $pages, fn (Client $saasu): array => $this->pages(
            static fn (int $page) => $saasu->invoices()->list($filter, $page),
            $pages,
        ));

        return [
            'count' => $result['count'],
            'more_exist' => $result['more_exist'],
            'note' => $this->moreNote($result),
            'dates' => Present::dateNote(),
            'invoices' => $result['records'],
        ];
    }
}
