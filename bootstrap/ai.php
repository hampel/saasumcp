<?php

use App\Mcp\Servers\SaasuServer;
use Laravel\Mcp\Facades\Mcp;

// Local only: a stdio server started with `saasumcp mcp:start saasu`. There is no Mcp::web()
// here and there must never be one - nothing in this tool listens on a port.
Mcp::local('saasu', SaasuServer::class);
