<?php

declare(strict_types=1);

/*
 * Laravel Zero has no cache store worth the name unless one is defined: without this file it
 * falls back to `array`, which keeps nothing past the process. That would lose three things, each
 * of which has to outlive one MCP session, because a client starts a new server process for each:
 *
 *   - Saasu's OAuth grant, requested again on every start and spending quota;
 *   - the one-request-a-second throttle, which two conversations at once must share;
 *   - the daily budget, which would reset every time a client reconnected.
 *
 * The store lives in the state directory, never under storage_path() inside a compiled binary -
 * that is a phar:// path and read-only. The state directory is created 0700 and that is the
 * protection: App\Support\StateDirectory says why `permission` is not set here.
 */

$path = (require base_path('bootstrap/state.php')).'/cache';

return [

    'default' => 'saasumcp',

    'stores' => [

        'saasumcp' => [
            'driver' => 'file',
            'path' => $path,
            'lock_path' => $path,
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

    ],

    'prefix' => 'saasumcp',

];
