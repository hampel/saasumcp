<?php

use App\Providers\AppServiceProvider;
use App\Support\Version;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;

/*
 * No env() call in this file, ever: app:build compiles it in as a literal, so a value read from
 * the environment here would be the build machine's.
 */
return [

    'name' => 'saasumcp',

    // git describe, compiled in by app:build: a tag is exactly itself, anything past one says so
    'version' => Version::describe(dirname(__DIR__)),

    'env' => 'development',

    'providers' => [
        AppServiceProvider::class,

        // Listed by hand: Laravel Zero ignores package auto-discovery. Without this line
        // SaasuManager is unbound, and Http::fake() would miss the client's requests.
        SaasuServiceProvider::class,

        // laravel/mcp validates every tools/call through the Validator facade from 1.2, and
        // Laravel Zero registers neither of these: without them every tool call answers
        // "Something went wrong" (found 2026-11-21, laravel/mcp 1.2.1). Validation needs the
        // translator for its messages.
        ValidationServiceProvider::class,
        TranslationServiceProvider::class,
    ],

];
