<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\Gateway;
use App\Support\Present;
use App\Support\SaasuFile;
use Hampel\Saasu\Api\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Ask Saasu whether this server's login reaches a file, and what the file says it is: its name, ABN, address
    and settings. One request, plus a login if none is cached.

    Use it when a file is new to you, or when another tool's error suggests the login is the problem. Check
    `Name`/`FullLegalName` against the business you expected: a login that reaches several files would
    answer for the wrong one without complaint. It does not show which permissions the login has; a tool that
    lacks one says so when refused.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class FileAccess extends SaasuTool
{
    protected function answer(Request $request, Gateway $gateway, SaasuFile $file): array
    {
        $identity = $gateway->call($file, 1, static fn (Client $saasu) => $saasu->files()->identity());

        return ['identity' => Present::record($identity)];
    }
}
