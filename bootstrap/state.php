<?php

declare(strict_types=1);

/*
 * The state directory, worked out once for the config files that need it.
 *
 * A file of its own because config files load in alphabetical order: cache.php is read before
 * saasumcp.php, so it cannot ask config() for a value it has not loaded yet.
 * config/saasumcp.php explains the default.
 */

return rtrim((string) (env('SAASUMCP_STATE_PATH') ?: storage_path()), '/');
