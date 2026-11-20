<?php

declare(strict_types=1);

/*
 * hampel/saasu-api-laravel's settings, replaced whole rather than merged into: an application
 * file of this name wins key by key, so every key the package reads is set here.
 *
 * NO CONNECTIONS ARE LISTED. One is registered per Saasu file at run time, from the file
 * inventory, by App\Saasu\Gateway - the files are only known once the inventory has been read.
 */
return [

    'default' => 'unused',

    'connections' => [],

    // shared by every server process through the cache store, so two conversations at once still
    // keep to Saasu's one request a second per file between them
    'throttle' => env('SAASU_THROTTLE', 'cache'),

    'cache_store' => 'saasumcp',

    // Saasu's maximum, and the package's default: every page is a request against the quota
    'page_size' => null,

    'base_uri' => env('SAASU_API_URL'),

    'timeout' => env('SAASU_TIMEOUT', 30),

    'connect_timeout' => env('SAASU_CONNECT_TIMEOUT', 5),

];
