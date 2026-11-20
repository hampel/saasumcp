<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| saasumcp
|--------------------------------------------------------------------------
|
| The state directory holds the cached Saasu tokens, the shared throttle, the daily request count
| for each file and, by default, the file inventory. The default is the storage path, which from
| a checkout is the project's storage/ and inside a compiled binary is the CURRENT WORKING
| DIRECTORY - which an MCP client chooses. So a deployment sets SAASUMCP_STATE_PATH absolutely,
| and app:validate fails a binary running on the derived one.
|
*/

$state = require base_path('bootstrap/state.php');

/*
 * Credentials, by file: SAASUMCP_<KEY>_USERNAME and SAASUMCP_<KEY>_PASSWORD, where <KEY> is the
 * file's key in the inventory, uppercased.
 *
 * Gathered by pattern rather than listed, because the files are named in the inventory and not
 * here. Dotenv puts what it loads into $_ENV and $_SERVER; an exported variable is in $_SERVER and
 * getenv(). Later sources do not overwrite earlier ones.
 */
$credentials = [];

foreach ([$_ENV, $_SERVER, getenv()] as $source) {
    foreach ($source as $name => $value) {
        if (is_string($name) && is_string($value)
            && preg_match('/^SAASUMCP_([A-Z0-9]+)_(USERNAME|PASSWORD)$/', $name, $m) === 1) {
            $credentials[strtolower($m[1])][strtolower($m[2])] ??= $value;
        }
    }
}

return [

    'state_path' => $state,

    // whether SAASUMCP_STATE_PATH was set, rather than derived - app:validate needs to know
    'state_path_set' => (bool) env('SAASUMCP_STATE_PATH'),

    'files_path' => env('SAASUMCP_FILES_PATH') ?: $state.'/files.toml',

    'credentials' => $credentials,

    /*
     * Requests this server may send to one Saasu file in any 24 hours, unless the file's entry in
     * the inventory sets its own `daily_budget`. Saasu's quota is a few thousand a day and
     * exceeding it blocks the WHOLE FILE for 24 hours - every integration on it, saasusync's daily
     * bookings included - so this stays well short of it. Decided by Simon, 2026-11-21.
     */
    'daily_budget' => (int) env('SAASUMCP_DAILY_BUDGET', 500),

    // A second timezone to show the instants Saasu reports in (fields ending Utc). Transaction
    // dates are calendar dates and are never converted.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

];
