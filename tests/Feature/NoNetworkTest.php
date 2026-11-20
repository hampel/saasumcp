<?php

use App\Mcp\Servers\SaasuServer;
use App\Mcp\Tools\GetInvoice;

/*
 * Fakes nothing. If the client ever stopped sending through Laravel's HTTP stack - a provider
 * dropped from config/app.php is enough - Http::fake() would stop seeing its requests and every
 * other test would quietly call Saasu.
 */
it('cannot reach Saasu without a fake', function () {
    SaasuServer::tool(GetInvoice::class, ['file' => 'acme', 'invoice_id' => 1])->assertHasErrors();

    expect(Http::recorded())->toBeEmpty();
});
